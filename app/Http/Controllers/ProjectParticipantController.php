<?php

namespace App\Http\Controllers;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Services\ProjectAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectParticipantController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectParticipant::query()
            ->with([
                'user:user_id,name,email',
            ])
            ->whereHas('project', function ($projectQuery) use ($user) {
                $projectQuery
                    ->where('owner_id', $user->user_id)
                    ->orWhereHas('participants', function ($participantQuery) use ($user) {
                        $participantQuery->where('user_id', $user->user_id);
                    });
            });

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(Request $request, int $participantId, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $project = Project::query()->findOrFail($participant->project_id);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $participant->loadMissing('user:user_id,name,email');
    }

    public function store(Request $request, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'role' => ['sometimes', 'string', Rule::in(ProjectParticipant::roles())],
            'joined_at' => ['nullable', 'date'],
        ]);

        $project = Project::query()->findOrFail((int) $data['project_id']);
        if (! $access->canManageParticipants($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ((int) $project->owner_id === (int) $data['user_id']) {
            return response()->json(['message' => 'Owner already has access.'], 422);
        }

        $requestedRole = ProjectParticipant::normalizeRole((string) ($data['role'] ?? ProjectParticipant::ROLE_DEVELOPER));
        if (! $access->isOwner($project, $user) && $requestedRole === ProjectParticipant::ROLE_MAINTAINER) {
            return response()->json(['message' => 'Only owner can grant maintainer role.'], 403);
        }

        try {
            $participant = ProjectParticipant::query()->create([
                'project_id' => (int) $data['project_id'],
                'user_id' => (int) $data['user_id'],
                'role' => $requestedRole,
                'joined_at' => $data['joined_at'] ?? now(),
            ]);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Participant already exists.'], 409);
        }

        $this->broadcastParticipantsUpdated(
            $project,
            (int) $user->user_id,
            'participant_added',
            [
                'participant_id' => (int) $participant->participant_id,
                'participant_user_id' => (int) $participant->user_id,
            ]
        );

        return response()->json(
            $participant->loadMissing('user:user_id,name,email'),
            201
        );
    }

    public function update(Request $request, int $participantId, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $project = Project::query()->findOrFail($participant->project_id);

        if (! $access->canManageParticipants($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $actorIsOwner = $access->isOwner($project, $user);
        $currentRole = ProjectParticipant::normalizeRole((string) ($participant->role ?? ProjectParticipant::ROLE_DEVELOPER));
        if (! $actorIsOwner && $currentRole === ProjectParticipant::ROLE_MAINTAINER) {
            return response()->json(['message' => 'Only owner can modify maintainer access.'], 403);
        }

        $data = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(ProjectParticipant::roles())],
            'joined_at' => ['nullable', 'date'],
        ]);

        if (array_key_exists('role', $data)) {
            $nextRole = ProjectParticipant::normalizeRole((string) $data['role']);
            if (! $actorIsOwner && $nextRole === ProjectParticipant::ROLE_MAINTAINER) {
                return response()->json(['message' => 'Only owner can grant maintainer role.'], 403);
            }
            $data['role'] = $nextRole;
        }

        $participant->fill($data);
        $participant->save();

        return $participant->loadMissing('user:user_id,name,email');
    }

    public function destroy(Request $request, int $participantId, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $project = Project::query()->findOrFail($participant->project_id);
        $isOwner = $access->isOwner($project, $user);
        $canManageParticipants = $access->canManageParticipants($project, $user);
        $isSelf = (int) $participant->user_id === (int) $user->user_id;

        if (! $canManageParticipants && ! $isSelf) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $targetRole = ProjectParticipant::normalizeRole((string) ($participant->role ?? ProjectParticipant::ROLE_DEVELOPER));
        if ($canManageParticipants && ! $isOwner && ! $isSelf && $targetRole === ProjectParticipant::ROLE_MAINTAINER) {
            return response()->json(['message' => 'Only owner can remove maintainer access.'], 403);
        }

        $removedParticipantId = (int) $participant->participant_id;
        $removedUserId = (int) $participant->user_id;
        $participant->delete();

        $this->broadcastParticipantsUpdated(
            $project,
            (int) $user->user_id,
            'participant_removed',
            [
                'participant_id' => $removedParticipantId,
                'participant_user_id' => $removedUserId,
            ]
        );

        return response()->noContent();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function broadcastParticipantsUpdated(Project $project, int $actorUserId, string $action, array $payload = []): void
    {
        try {
            event(new ProjectRealtimeEvent(
                (int) $project->project_id,
                $actorUserId,
                'realtime.project.participants.updated',
                array_merge(
                    [
                        'action' => $action,
                    ],
                    $payload
                )
            ));
        } catch (\Throwable) {
            // Presence updates are best-effort and must not break participant writes.
        }
    }
}

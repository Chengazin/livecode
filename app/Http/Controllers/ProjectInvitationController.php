<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Services\ProjectAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectInvitationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectInvitation::query()
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

        if ($request->filled('inviter_user_id')) {
            $query->where('inviter_user_id', (int) $request->query('inviter_user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(
        Request $request,
        int $invitationId,
        ProjectAccessService $access
    )
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $project = Project::query()->findOrFail($invitation->project_id);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $invitation;
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'invite_token' => ['sometimes', 'string', 'max:128', 'unique:project_invitations,invite_token'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $project = Project::query()->findOrFail((int) $data['project_id']);
        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $payload = [
            'project_id' => (int) $data['project_id'],
            'inviter_user_id' => (int) $user->user_id,
            'invite_token' => (string) ($data['invite_token'] ?? $this->generateInviteToken()),
            'expires_at' => $data['expires_at'] ?? null,
        ];

        try {
            $invitation = ProjectInvitation::query()->create($payload);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Invitation token already exists.'], 409);
        }

        return response()->json($invitation, 201);
    }

    public function update(Request $request, int $invitationId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $project = Project::query()->findOrFail($invitation->project_id);

        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'invite_token' => [
                'sometimes',
                'string',
                'max:128',
                Rule::unique('project_invitations', 'invite_token')->ignore($invitation->invitation_id, 'invitation_id'),
            ],
            'expires_at' => ['nullable', 'date'],
        ]);

        $invitation->fill($data);
        $invitation->save();

        return $invitation;
    }

    public function destroy(Request $request, int $invitationId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $project = Project::query()->findOrFail($invitation->project_id);

        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $invitation->delete();

        return response()->noContent();
    }

    private function isOwner(Project $project, int $userId): bool
    {
        return (int) $project->owner_id === $userId;
    }

    private function generateInviteToken(): string
    {
        do {
            $token = Str::random(64);
        } while (ProjectInvitation::query()->where('invite_token', $token)->exists());

        return $token;
    }
}

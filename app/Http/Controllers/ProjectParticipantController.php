<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Services\ProjectAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

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

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'joined_at' => ['nullable', 'date'],
        ]);

        $project = Project::query()->findOrFail((int) $data['project_id']);
        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ((int) $project->owner_id === (int) $data['user_id']) {
            return response()->json(['message' => 'Owner already has access.'], 422);
        }

        try {
            $participant = ProjectParticipant::query()->create($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Participant already exists.'], 409);
        }

        return response()->json(
            $participant->loadMissing('user:user_id,name,email'),
            201
        );
    }

    public function update(Request $request, int $participantId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $project = Project::query()->findOrFail($participant->project_id);

        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'joined_at' => ['nullable', 'date'],
        ]);

        $participant->fill($data);
        $participant->save();

        return $participant->loadMissing('user:user_id,name,email');
    }

    public function destroy(Request $request, int $participantId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $project = Project::query()->findOrFail($participant->project_id);
        $isOwner = $this->isOwner($project, (int) $user->user_id);
        $isSelf = (int) $participant->user_id === (int) $user->user_id;

        if (! $isOwner && ! $isSelf) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $participant->delete();

        return response()->noContent();
    }

    private function isOwner(Project $project, int $userId): bool
    {
        return (int) $project->owner_id === $userId;
    }
}

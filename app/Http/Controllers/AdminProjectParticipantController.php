<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectParticipant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class AdminProjectParticipantController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $search = trim((string) $request->query('search', ''));
        $projectId = trim((string) $request->query('project_id', ''));
        $userId = trim((string) $request->query('user_id', ''));
        $likeOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = ProjectParticipant::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'user:user_id,name,email',
            ])
            ->orderByDesc('joined_at')
            ->orderByDesc('participant_id');

        if ($projectId !== '' && ctype_digit($projectId)) {
            $query->where('project_id', (int) $projectId);
        }

        if ($userId !== '' && ctype_digit($userId)) {
            $query->where('user_id', (int) $userId);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $likeOperator) {
                $builder
                    ->whereHas('project', function ($projectQuery) use ($search, $likeOperator) {
                        $projectQuery
                            ->where('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('description', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $projectQuery->orWhere('project_id', (int) $search);
                        }
                    })
                    ->orWhereHas('user', function ($userQuery) use ($search, $likeOperator) {
                        $userQuery
                            ->where('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('email', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $userQuery->orWhere('user_id', (int) $search);
                        }
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('participant_id', (int) $search);
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function show(int $participantId)
    {
        return ProjectParticipant::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'user:user_id,name,email',
            ])
            ->findOrFail($participantId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'joined_at' => ['nullable', 'date'],
        ]);

        $project = Project::query()->findOrFail((int) $data['project_id']);
        if ((int) $project->owner_id === (int) $data['user_id']) {
            return response()->json(['message' => 'Owner already has access.'], 422);
        }

        try {
            $participant = ProjectParticipant::query()->create([
                'project_id' => (int) $data['project_id'],
                'user_id' => (int) $data['user_id'],
                'joined_at' => $data['joined_at'] ?? now(),
            ]);
        } catch (QueryException $exception) {
            return response()->json(['message' => 'Participant already exists.'], 409);
        }

        return response()->json(
            $participant->load([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'user:user_id,name,email',
            ]),
            201
        );
    }

    public function update(Request $request, int $participantId)
    {
        $participant = ProjectParticipant::query()->findOrFail($participantId);

        $data = $request->validate([
            'project_id' => ['sometimes', 'integer', 'exists:projects,project_id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,user_id'],
            'joined_at' => ['nullable', 'date'],
        ]);

        $nextProjectId = (int) ($data['project_id'] ?? $participant->project_id);
        $nextUserId = (int) ($data['user_id'] ?? $participant->user_id);

        $project = Project::query()->findOrFail($nextProjectId);
        if ((int) $project->owner_id === $nextUserId) {
            return response()->json(['message' => 'Owner already has access.'], 422);
        }

        $participant->fill($data);

        try {
            $participant->save();
        } catch (QueryException $exception) {
            return response()->json(['message' => 'Participant already exists.'], 409);
        }

        return $participant->load([
            'project:project_id,name,owner_id,is_public',
            'project.owner:user_id,name,email',
            'user:user_id,name,email',
        ]);
    }

    public function destroy(int $participantId)
    {
        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $participant->delete();

        return response()->noContent();
    }
}

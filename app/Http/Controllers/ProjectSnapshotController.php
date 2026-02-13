<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Services\ProjectAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectSnapshotController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectSnapshot::query()
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

        if ($request->filled('author_user_id')) {
            $query->where('author_user_id', (int) $request->query('author_user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(Request $request, int $snapshotId, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);
        $project = Project::query()->findOrFail($snapshot->project_id);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $snapshot;
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'snapshot_hash' => ['required', 'string', 'size:64', 'unique:project_snapshots,snapshot_hash'],
            'message' => ['nullable', 'string'],
            'snapshot_path' => ['required', 'string', 'max:2048'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'created_at' => ['nullable', 'date'],
        ]);

        $project = Project::query()->findOrFail((int) $data['project_id']);
        if (! $this->canAccessProject($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $payload = $data;
        $payload['author_user_id'] = $user->user_id;

        try {
            $snapshot = ProjectSnapshot::query()->create($payload);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Snapshot already exists.'], 409);
        }

        return response()->json($snapshot, 201);
    }

    public function update(Request $request, int $snapshotId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);
        $project = Project::query()->findOrFail($snapshot->project_id);

        if (! $this->canManageSnapshot($project, $snapshot, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'snapshot_hash' => [
                'sometimes',
                'string',
                'size:64',
                Rule::unique('project_snapshots', 'snapshot_hash')->ignore($snapshot->snapshot_id, 'snapshot_id'),
            ],
            'message' => ['nullable', 'string'],
            'snapshot_path' => ['sometimes', 'string', 'max:2048'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
        ]);

        $snapshot->fill($data);
        $snapshot->save();

        return $snapshot;
    }

    public function destroy(Request $request, int $snapshotId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);
        $project = Project::query()->findOrFail($snapshot->project_id);

        if (! $this->canManageSnapshot($project, $snapshot, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $snapshot->delete();

        return response()->noContent();
    }

    private function canAccessProject(Project $project, int $userId): bool
    {
        if ((int) $project->owner_id === $userId) {
            return true;
        }

        return $project->participants()
            ->where('user_id', $userId)
            ->exists();
    }

    private function canManageSnapshot(Project $project, ProjectSnapshot $snapshot, int $userId): bool
    {
        if ((int) $project->owner_id === $userId) {
            return true;
        }

        return $snapshot->author_user_id !== null && (int) $snapshot->author_user_id === $userId;
    }
}

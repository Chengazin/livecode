<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\Project\ProjectDirectoryService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        return Project::query()
            ->where(function ($query) use ($user) {
                $query
                    ->where('owner_id', $user->user_id)
                    ->orWhereHas('participants', function ($participantQuery) use ($user) {
                        $participantQuery->where('user_id', $user->user_id);
                    });
            })
            ->with([
                'owner:user_id,name,email',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function show(Request $request, int $projectId, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $project;
    }

    public function store(Request $request, ProjectDirectoryService $directories)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $projectPath = $directories->createProjectDirectory((int) $user->user_id, (string) $user->email, (string) $data['name']);

        try {
            $project = Project::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => $user->user_id,
                'project_path' => $projectPath,
                'is_public' => $data['is_public'] ?? false,
            ]);
        } catch (\Throwable $e) {
            $directories->deleteProjectDirectory($projectPath);
            throw $e;
        }

        return response()->json($project, 201);
    }

    public function update(Request $request, int $projectId, ProjectAccessService $access)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->canManageSettings($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $project->fill($data);
        $project->save();

        return $project;
    }

    public function destroy(Request $request, int $projectId, ProjectDirectoryService $directories)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $this->isOwner($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $directories->deleteProjectDirectory((string) $project->project_path);
        $project->delete();

        return response()->noContent();
    }

    private function isOwner(Project $project, User $user): bool
    {
        return (int) $project->owner_id === (int) $user->user_id;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\Project\ProjectDirectoryService;
use Illuminate\Http\Request;

class AdminProjectController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $search = trim((string) $request->query('search', ''));
        $owner = trim((string) $request->query('owner', ''));
        $likeOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = Project::query()
            ->with(['owner:user_id,name,email'])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $likeOperator) {
                $builder
                    ->where('name', $likeOperator, '%'.$search.'%')
                    ->orWhere('description', $likeOperator, '%'.$search.'%')
                    ->orWhere('project_path', $likeOperator, '%'.$search.'%')
                    ->orWhereHas('owner', function ($ownerQuery) use ($search, $likeOperator) {
                        $ownerQuery
                            ->where('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('email', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $ownerQuery->orWhere('user_id', (int) $search);
                        }
                    });
            });
        }

        if ($owner !== '') {
            $query->whereHas('owner', function ($ownerQuery) use ($owner, $likeOperator) {
                if (ctype_digit($owner)) {
                    $ownerQuery->where('user_id', (int) $owner);
                    return;
                }

                $ownerQuery
                    ->where('name', $likeOperator, '%'.$owner.'%')
                    ->orWhere('email', $likeOperator, '%'.$owner.'%');
            });
        }

        if ($request->has('is_public')) {
            $query->where('is_public', filter_var($request->query('is_public'), FILTER_VALIDATE_BOOL));
        }

        return $query->paginate($perPage);
    }

    public function show(int $projectId)
    {
        return Project::query()
            ->with(['owner:user_id,name,email'])
            ->findOrFail($projectId);
    }

    public function store(Request $request, ProjectDirectoryService $directories)
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,user_id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $owner = User::query()->findOrFail((int) $data['owner_id']);
        $projectPath = $directories->createProjectDirectory((int) $owner->user_id, (string) $owner->email, (string) $data['name']);

        try {
            $project = Project::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => (int) $data['owner_id'],
                'project_path' => $projectPath,
                'is_public' => $data['is_public'] ?? false,
            ]);
        } catch (\Throwable $e) {
            $directories->deleteProjectDirectory($projectPath);
            throw $e;
        }

        return response()->json($project->load('owner:user_id,name,email'), 201);
    }

    public function update(Request $request, int $projectId)
    {
        $project = Project::query()->findOrFail($projectId);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $project->fill($data);
        $project->save();

        return $project->load('owner:user_id,name,email');
    }

    public function destroy(int $projectId, ProjectDirectoryService $directories)
    {
        $project = Project::query()->findOrFail($projectId);
        $directories->deleteProjectDirectory((string) $project->project_path);
        $project->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    public function store(Request $request)
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,user_id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $owner = User::query()->findOrFail((int) $data['owner_id']);
        $projectPath = $this->createProjectDirectory($owner->user_id, $owner->email, $data['name']);

        try {
            $project = Project::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => (int) $data['owner_id'],
                'project_path' => $projectPath,
                'is_public' => $data['is_public'] ?? false,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory($projectPath);
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

    public function destroy(int $projectId)
    {
        $project = Project::query()->findOrFail($projectId);
        $this->deleteProjectDirectory($project);
        $project->delete();

        return response()->noContent();
    }

    private function createProjectDirectory(int $userId, string $email, string $projectName): string
    {
        $disk = Storage::disk('local');
        $root = 'projects';

        if (! $disk->exists($root)) {
            $disk->makeDirectory($root);
        }

        $userSegment = $this->sanitizePathSegment($userId.'_'.$email);
        $userPath = $root.'/'.$userSegment;

        if (! $disk->exists($userPath)) {
            $disk->makeDirectory($userPath);
        }

        $projectSegment = $this->sanitizePathSegment($projectName);
        $basePath = $userPath.'/'.$projectSegment;
        $path = $basePath;
        $suffix = 2;

        while ($disk->exists($path) || Project::query()->where('project_path', $path)->exists()) {
            $path = $basePath.'-'.$suffix;
            $suffix++;
        }

        if (! $disk->makeDirectory($path)) {
            abort(500, 'Failed to create project directory.');
        }

        return $path;
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\/\\\\]+/', '-', $value);
        $value = preg_replace('/[:*?"<>|]/', '', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value, " .\t\n\r\0\x0B");

        if ($value === '') {
            return 'untitled';
        }

        return $value;
    }

    private function deleteProjectDirectory(Project $project): void
    {
        $projectPath = trim((string) $project->project_path, '/');

        if ($projectPath === '') {
            return;
        }

        Storage::disk('local')->deleteDirectory($projectPath);
    }
}

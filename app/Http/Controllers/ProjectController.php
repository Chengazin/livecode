<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        return Project::query()->paginate($perPage);
    }

    public function show(int $projectId)
    {
        return Project::query()->findOrFail($projectId);
    }

    public function store(Request $request)
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

        $projectPath = $this->createProjectDirectory($user->user_id, $user->email, $data['name']);

        try {
            $project = Project::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => $user->user_id,
                'project_path' => $projectPath,
                'is_public' => $data['is_public'] ?? false,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory($projectPath);
            throw $e;
        }

        return response()->json($project, 201);
    }

    public function update(Request $request, int $projectId)
    {
        $project = Project::query()->findOrFail($projectId);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['sometimes', 'integer', 'exists:users,user_id'],
            'project_path' => [
                'sometimes',
                'string',
                'max:2048',
                Rule::unique('projects', 'project_path')->ignore($project->project_id, 'project_id'),
            ],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $project->fill($data);
        $project->save();

        return $project;
    }

    public function destroy(int $projectId)
    {
        $project = Project::query()->findOrFail($projectId);
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
}

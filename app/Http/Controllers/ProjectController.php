<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $this->isOwner($project, $user)) {
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

    public function destroy(Request $request, int $projectId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $this->isOwner($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

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

    private function isOwner(Project $project, User $user): bool
    {
        return (int) $project->owner_id === (int) $user->user_id;
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

<?php

namespace App\Http\Controllers;

use App\Events\ProjectFilesystemEvent;
use App\Models\Project;
use App\Services\ProjectAccessService;
use App\Services\ProjectFilesystemService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ProjectFilesystemController extends Controller
{
    public function handle(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['create_file', 'create_folder'])],
            'path' => ['required', 'string', 'max:2048'],
            'content' => ['required_if:action,create_file', 'string'],
        ]);

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        try {
            if ($data['action'] === 'create_file') {
                $path = $filesystem->createFile($project, $data['path'], (string) $data['content']);
                $event = 'file_created';
            } else {
                $path = $filesystem->createFolder($project, $data['path']);
                $event = 'folder_created';
            }
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'invalid_path') {
                return response()->json(['error' => 'invalid_path'], 422);
            }

            if ($e->getMessage() === 'invalid_project_root') {
                return response()->json(['error' => 'invalid_project_root'], 500);
            }

            throw $e;
        }

        event(new ProjectFilesystemEvent($project->project_id, $user->user_id, $event, $path));

        return response()->json([
            'status' => 'ok',
            'action' => $data['action'],
            'path' => $path,
        ], 201);
    }
}

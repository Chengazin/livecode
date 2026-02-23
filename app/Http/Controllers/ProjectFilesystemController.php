<?php

namespace App\Http\Controllers;

use App\Events\ProjectFilesystemEvent;
use App\Models\Project;
use App\Services\ProjectAccessService;
use App\Services\ProjectFilesystemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectFilesystemController extends Controller
{
    public function handle(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['create_file', 'create_folder'])],
            'path' => ['required', 'string', 'max:2048'],
            'content' => ['present_if:action,create_file', 'nullable', 'string'],
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
            return $this->filesystemExceptionToResponse($e);
        }

        $this->broadcastSafely(new ProjectFilesystemEvent($project->project_id, $user->user_id, $event, $path));

        return response()->json([
            'status' => 'ok',
            'action' => $data['action'],
            'path' => $path,
        ], 201);
    }

    public function tree(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        try {
            $items = $filesystem->listTree($project);
        } catch (InvalidArgumentException $e) {
            return $this->filesystemExceptionToResponse($e);
        }

        return response()->json([
            'status' => 'ok',
            'project_id' => $project->project_id,
            'items' => $items,
        ]);
    }

    public function readFile(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        try {
            $file = $filesystem->readFile($project, $data['path']);
        } catch (InvalidArgumentException $e) {
            return $this->filesystemExceptionToResponse($e);
        }

        return response()->json([
            'status' => 'ok',
            'project_id' => $project->project_id,
            ...$file,
        ]);
    }

    public function writeFile(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'content' => ['present', 'nullable', 'string'],
        ]);

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        try {
            $file = $filesystem->writeFile($project, $data['path'], (string) ($data['content'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return $this->filesystemExceptionToResponse($e);
        }

        $this->broadcastSafely(new ProjectFilesystemEvent($project->project_id, $user->user_id, 'file_saved', $file['path']));

        return response()->json([
            'status' => 'ok',
            'project_id' => $project->project_id,
            ...$file,
        ]);
    }

    public function deletePath(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        try {
            $deleted = $filesystem->deletePath($project, $data['path']);
        } catch (InvalidArgumentException $e) {
            return $this->filesystemExceptionToResponse($e);
        }

        $this->broadcastSafely(new ProjectFilesystemEvent($project->project_id, $user->user_id, 'path_deleted', $deleted['path']));

        return response()->json([
            'status' => 'ok',
            'project_id' => $project->project_id,
            ...$deleted,
        ]);
    }

    public function movePath(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:2048'],
            'to_path' => ['required', 'string', 'max:2048'],
        ]);

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        try {
            $moved = $filesystem->movePath($project, $data['from_path'], $data['to_path']);
        } catch (InvalidArgumentException $e) {
            return $this->filesystemExceptionToResponse($e);
        }

        $this->broadcastSafely(new ProjectFilesystemEvent($project->project_id, $user->user_id, 'path_moved', $moved['to_path']));

        return response()->json([
            'status' => 'ok',
            'project_id' => $project->project_id,
            ...$moved,
        ]);
    }

    public function download(
        Request $request,
        int $projectId,
        ProjectFilesystemService $filesystem,
        ProjectAccessService $access
    ): BinaryFileResponse|JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:2048'],
        ]);

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        $requestedPath = array_key_exists('path', $data) ? (string) $data['path'] : null;
        if ($requestedPath !== null && trim($requestedPath) === '') {
            $requestedPath = null;
        }

        try {
            $archive = $filesystem->createZipArchive($project, $requestedPath);
        } catch (InvalidArgumentException $e) {
            return $this->filesystemExceptionToResponse($e);
        }

        return response()->download(
            $archive['archive_path'],
            $archive['download_name'],
            ['Content-Type' => 'application/zip']
        )->deleteFileAfterSend(true);
    }

    private function filesystemExceptionToResponse(InvalidArgumentException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'invalid_path' => response()->json(['error' => 'invalid_path'], 422),
            'not_found' => response()->json(['error' => 'not_found'], 404),
            'same_path' => response()->json(['error' => 'same_path'], 422),
            'target_exists' => response()->json(['error' => 'target_exists'], 409),
            'invalid_target' => response()->json(['error' => 'invalid_target'], 422),
            'move_failed' => response()->json(['error' => 'move_failed'], 500),
            'not_file' => response()->json(['error' => 'not_file'], 422),
            'binary_file' => response()->json(['error' => 'binary_file'], 422),
            'invalid_project_root' => response()->json(['error' => 'invalid_project_root'], 500),
            'zip_unavailable' => response()->json(['error' => 'zip_unavailable'], 500),
            'archive_failed' => response()->json(['error' => 'archive_failed'], 500),
            default => throw $e,
        };
    }

    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable) {
            // Broadcast availability should not break API write paths.
        }
    }

}

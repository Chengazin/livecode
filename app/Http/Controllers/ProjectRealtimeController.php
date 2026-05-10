<?php
namespace App\Http\Controllers;
use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\ProjectRealtime\ProjectRealtimeChatService;
use App\Services\ProjectRealtime\ProjectRealtimeEditorService;
use App\Services\ProjectRealtime\ProjectRealtimePresenceService;
use App\Services\ProjectRealtime\ProjectRealtimeResult;
use App\Support\ProjectRealtime\ProjectRealtimeValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class ProjectRealtimeController extends Controller
{
    public function heartbeat(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectRealtimePresenceService $presenceService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $data = $request->validate(ProjectRealtimeValidation::heartbeatRules());
        $result = $presenceService->heartbeat($project->project_id, $user, $data);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }
    public function presence(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectRealtimePresenceService $presenceService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $result = $presenceService->presence($project->project_id, (int) $user->user_id);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    public function chatIndex(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectRealtimeChatService $chatService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $data = $request->validate(ProjectRealtimeValidation::chatIndexRules());
        $afterId = (int) ($data['after_id'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);
        $result = $chatService->index($project->project_id, $afterId, $limit);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    public function chatStore(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectRealtimeChatService $chatService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $data = $request->validate(ProjectRealtimeValidation::chatStoreRules());
        $result = $chatService->store($project->project_id, $user, (string) $data['message']);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    public function chatUpdate(
        Request $request,
        int $projectId,
        int $messageId,
        ProjectAccessService $access,
        ProjectRealtimeChatService $chatService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $data = $request->validate(ProjectRealtimeValidation::chatStoreRules());
        $result = $chatService->update(
            $project->project_id,
            $messageId,
            $user,
            (string) $data['message']
        );
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    public function chatDestroy(
        Request $request,
        int $projectId,
        int $messageId,
        ProjectAccessService $access,
        ProjectRealtimeChatService $chatService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $result = $chatService->destroy($project->project_id, $messageId, $user);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    public function editorSync(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectRealtimeEditorService $editorService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $data = $request->validate(ProjectRealtimeValidation::editorSyncRules());
        $result = $editorService->sync($project->project_id, $user, $data);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    public function editorState(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectRealtimeEditorService $editorService
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $project = $this->resolveProject($projectId, $user, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $canWriteProject = $access->canWriteProject($project, $user);
        $data = $request->validate(ProjectRealtimeValidation::editorStateRules());
        $result = $editorService->state($project->project_id, $canWriteProject, $data);
        return $this->respondWithResult($project, (int) $user->user_id, $result);
    }

    private function resolveProject(
        int $projectId,
        User $user,
        ProjectAccessService $access,
        bool $requiresWrite
    ): Project|JsonResponse {
        $project = Project::query()->findOrFail($projectId);
        $hasAccess = $requiresWrite
            ? $access->canWriteProject($project, $user)
            : $access->userHasAccess($project, $user);
        if (! $hasAccess) {
            return response()->json(['message' => 'Access denied.'], 403);
        }
        return $project;
    }

    private function respondWithResult(
        Project $project,
        int $actorUserId,
        ProjectRealtimeResult $result
    ): JsonResponse {
        if ($result->eventName !== null) {
            $this->broadcastSafely(new ProjectRealtimeEvent(
                $project->project_id,
                $actorUserId,
                $result->eventName,
                $result->eventPayload
            ));
        }
        return response()->json($result->body, $result->status);
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

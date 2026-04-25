<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectTask\AssignProjectTaskRequest;
use App\Http\Requests\ProjectTask\IndexProjectTaskRequest;
use App\Http\Requests\ProjectTask\StoreProjectTaskRequest;
use App\Http\Requests\ProjectTask\UpdateProjectTaskRequest;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectAccessService;
use App\Services\ProjectTask\ProjectTaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectTaskController extends Controller
{
    public function index(
        IndexProjectTaskRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $tasks = $workflow->list(
            $projectId,
            $request->status(),
            $request->assignedToUserId(),
            $request->sortBy(),
            $request->perPage()
        );

        return response()->json([
            'success' => true,
            'data' => $tasks->items(),
            'pagination' => [
                'total' => $tasks->total(),
                'per_page' => $tasks->perPage(),
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }

    public function store(
        StoreProjectTaskRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $result = $workflow->create($projectId, $user, $request->payload());

        return response()->json($result->payload, $result->status);
    }

    public function show(Request $request, int $projectId, int $projectTaskId, ProjectAccessService $access): JsonResponse
    {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)
            ->with(['createdBy', 'assignedTo', 'project'])
            ->findOrFail($projectTaskId);

        return response()->json([
            'success' => true,
            'data' => $task,
        ]);
    }

    public function update(
        UpdateProjectTaskRequest $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->update($task, $request->payload());

        return response()->json($result->payload, $result->status);
    }

    public function assignTask(
        AssignProjectTaskRequest $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->assign($project, $task, $request->userId(), $access);

        return response()->json($result->payload, $result->status);
    }

    public function startTask(
        Request $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->start($project, $task, $user, $access);

        return response()->json($result->payload, $result->status);
    }

    public function completeTask(
        Request $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->complete($task);

        return response()->json($result->payload, $result->status);
    }

    public function reopenTask(
        Request $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->reopen($task);

        return response()->json($result->payload, $result->status);
    }

    public function unassignTask(
        Request $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->unassign($task);

        return response()->json($result->payload, $result->status);
    }

    public function destroy(
        Request $request,
        int $projectId,
        int $projectTaskId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($projectTaskId);
        $result = $workflow->delete($task);

        return response()->json($result->payload, $result->status);
    }

    public function getStats(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTaskWorkflowService $workflow
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return response()->json([
            'success' => true,
            'data' => $workflow->stats($projectId),
        ]);
    }

    private function resolveProject(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        bool $requiresTaskManagement
    ): Project|JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        $allowed = $requiresTaskManagement
            ? $access->canManageTasks($project, $user)
            : $access->userHasAccess($project, $user);

        if (! $allowed) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $project;
    }
}

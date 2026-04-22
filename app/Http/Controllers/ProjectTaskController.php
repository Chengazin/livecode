<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\ProjectTaskService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectTaskController extends Controller
{
    /**
     * Get all tasks for a project.
     * GET /api/projects/{projectId}/tasks
     */
    public function index(Request $request, int $projectId, ProjectAccessService $access): JsonResponse
    {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                ProjectTask::STATUS_BACKLOG,
                ProjectTask::STATUS_IN_PROGRESS,
                ProjectTask::STATUS_DONE,
            ])],
            'assigned_to_user_id' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', Rule::in(['priority', 'due_date', 'created'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tasks = ProjectTaskService::getProjectTasks(
            $projectId,
            $validated['status'] ?? null,
            isset($validated['assigned_to_user_id']) ? (int) $validated['assigned_to_user_id'] : null,
            $validated['sort_by'] ?? 'priority',
            (int) ($validated['per_page'] ?? 15)
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

    /**
     * Create a new task.
     * POST /api/projects/{projectId}/tasks
     */
    public function store(Request $request, int $projectId, ProjectAccessService $access): JsonResponse
    {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'priority' => 'nullable|integer|in:0,1,2,3',
            'due_date' => 'nullable|date',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $task = ProjectTaskService::createTask(
            $projectId,
            (int) $user->user_id,
            $validated['title'],
            $validated['description'] ?? null,
            (int) ($validated['priority'] ?? ProjectTask::PRIORITY_MEDIUM),
            isset($validated['due_date']) ? new \DateTimeImmutable((string) $validated['due_date']) : null
        );

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ], 201);
    }

    /**
     * Get a specific task.
     * GET /api/projects/{projectId}/tasks/{taskId}
     */
    public function show(Request $request, int $projectId, int $taskId, ProjectAccessService $access): JsonResponse
    {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)
            ->with(['createdBy', 'assignedTo', 'project'])
            ->findOrFail($taskId);

        return response()->json([
            'success' => true,
            'data' => $task,
        ]);
    }

    /**
     * Update a task.
     * PATCH /api/projects/{projectId}/tasks/{taskId}
     */
    public function update(Request $request, int $projectId, int $taskId, ProjectAccessService $access): JsonResponse
    {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'priority' => 'nullable|integer|in:0,1,2,3',
            'due_date' => 'nullable|date',
            'status' => ['nullable', 'string', Rule::in([
                ProjectTask::STATUS_BACKLOG,
                ProjectTask::STATUS_IN_PROGRESS,
                ProjectTask::STATUS_DONE,
            ])],
        ]);

        try {
            $task = ProjectTaskService::updateTask($task, $validated);
        } catch (DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * Assign task to a user.
     * POST /api/projects/{projectId}/tasks/{taskId}/assign
     */
    public function assignTask(
        Request $request,
        int $projectId,
        int $taskId,
        ProjectAccessService $access
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,user_id',
        ]);

        $assignee = User::query()->findOrFail((int) $validated['user_id']);
        if (!$access->userHasAccess($project, $assignee)) {
            return response()->json([
                'error' => 'Assignee must have access to the project',
            ], 422);
        }

        try {
            $task = ProjectTaskService::assignTaskToUser($task, (int) $validated['user_id']);
        } catch (DomainException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
                'wip_slots' => ProjectTaskService::getWipSlots($projectId, (int) $validated['user_id']),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * Start a task (move from Backlog to In Progress).
     * POST /api/projects/{projectId}/tasks/{taskId}/start
     */
    public function startTask(
        Request $request,
        int $projectId,
        int $taskId,
        ProjectAccessService $access
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if (!$task->assigned_to_user_id) {
            if (!ProjectTaskService::canStartNewTask($projectId, (int) $user->user_id)) {
                return response()->json([
                    'error' => 'You have reached WIP limit (3 active tasks)',
                    'wip_slots' => ProjectTaskService::getWipSlots($projectId, (int) $user->user_id),
                ], 422);
            }

            $task->update([
                'assigned_to_user_id' => (int) $user->user_id,
                'assigned_at' => now(),
            ]);
        }

        try {
            $task = ProjectTaskService::startTask($task);
        } catch (DomainException $exception) {
            $targetUserId = (int) ($task->assigned_to_user_id ?: $user->user_id);

            return response()->json([
                'error' => $exception->getMessage(),
                'wip_slots' => ProjectTaskService::getWipSlots($projectId, $targetUserId),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * Complete a task (mark as Done).
     * POST /api/projects/{projectId}/tasks/{taskId}/complete
     */
    public function completeTask(
        Request $request,
        int $projectId,
        int $taskId,
        ProjectAccessService $access
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

        try {
            $task = ProjectTaskService::completeTask($task);
        } catch (DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * Reopen a completed task (move from Done back to Backlog).
     * POST /api/projects/{projectId}/tasks/{taskId}/reopen
     */
    public function reopenTask(
        Request $request,
        int $projectId,
        int $taskId,
        ProjectAccessService $access
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

        try {
            $task = ProjectTaskService::reopenTask($task);
        } catch (DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * Unassign a task.
     * POST /api/projects/{projectId}/tasks/{taskId}/unassign
     */
    public function unassignTask(
        Request $request,
        int $projectId,
        int $taskId,
        ProjectAccessService $access
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);
        $task = ProjectTaskService::unassignTask($task);

        return response()->json([
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * Delete a task.
     * DELETE /api/projects/{projectId}/tasks/{taskId}
     */
    public function destroy(
        Request $request,
        int $projectId,
        int $taskId,
        ProjectAccessService $access
    ): JsonResponse {
        $project = $this->resolveProject($request, $projectId, $access, true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $task = ProjectTask::forProject($projectId)->findOrFail($taskId);
        ProjectTaskService::deleteTask($task);

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully',
        ]);
    }

    /**
     * Get task statistics for a project.
     * GET /api/projects/{projectId}/tasks/stats
     */
    public function getStats(Request $request, int $projectId, ProjectAccessService $access): JsonResponse
    {
        $project = $this->resolveProject($request, $projectId, $access, false);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $stats = ProjectTaskService::getTaskStats($projectId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    private function resolveProject(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        bool $requiresTaskManagement
    ): Project|JsonResponse {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        $allowed = $requiresTaskManagement
            ? $access->canManageTasks($project, $user)
            : $access->userHasAccess($project, $user);

        if (!$allowed) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $project;
    }
}

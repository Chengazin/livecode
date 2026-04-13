<?php

namespace App\Http\Controllers;

use App\Models\ProjectTask;
use App\Services\ProjectTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectTaskController extends Controller
{
    /**
     * Get all tasks for a project
     * GET /api/projects/{projectId}/tasks
     */
    public function index(Request $request, int $projectId): JsonResponse
    {
        try {
            $status = $request->query('status');
            $assignedToUserId = $request->query('assigned_to_user_id') ? (int)$request->query('assigned_to_user_id') : null;
            $sortBy = $request->query('sort_by', 'priority');
            $perPage = (int)$request->query('per_page', 15);

            $tasks = ProjectTaskService::getProjectTasks(
                $projectId,
                $status,
                $assignedToUserId,
                $sortBy,
                $perPage
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
        } catch (\Exception $e) {
            Log::error('Failed to get project tasks', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to retrieve tasks'], 500);
        }
    }

    /**
     * Create a new task
     * POST /api/projects/{projectId}/tasks
     */
    public function store(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:2000',
                'priority' => 'nullable|integer|in:0,1,2,3',
                'due_date' => 'nullable|date',
            ]);

            $userId = (int)($request->user()->user_id ?? 0);

            $task = ProjectTaskService::createTask(
                $projectId,
                $userId,
                $validated['title'],
                $validated['description'] ?? null,
                $validated['priority'] ?? ProjectTask::PRIORITY_MEDIUM,
                $validated['due_date'] ? new \DateTime($validated['due_date']) : null
            );

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create project task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to create task'], 500);
        }
    }

    /**
     * Get a specific task
     * GET /api/projects/{projectId}/tasks/{taskId}
     */
    public function show(int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)
                ->with(['createdBy', 'assignedTo', 'project'])
                ->findOrFail($taskId);

            return response()->json([
                'success' => true,
                'data' => $task,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get project task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Task not found'], 404);
        }
    }

    /**
     * Update a task
     * PATCH /api/projects/{projectId}/tasks/{taskId}
     */
    public function update(Request $request, int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

            $validated = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:2000',
                'priority' => 'nullable|integer|in:0,1,2,3',
                'due_date' => 'nullable|date',
                'status' => 'nullable|string|in:backlog,in_progress,done',
            ]);

            $task = ProjectTaskService::updateTask($task, $validated);

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update project task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to update task'], 500);
        }
    }

    /**
     * Assign task to a user
     * POST /api/projects/{projectId}/tasks/{taskId}/assign
     */
    public function assignTask(Request $request, int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,user_id',
            ]);

            // Check WIP limit
            if (!ProjectTaskService::canStartNewTask($projectId, $validated['user_id'])) {
                return response()->json([
                    'error' => 'User has reached WIP limit (3 active tasks)',
                    'wip_slots' => 0,
                ], 422);
            }

            $task = ProjectTaskService::assignTaskToUser($task, $validated['user_id']);

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to assign task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to assign task'], 500);
        }
    }

    /**
     * Start a task (move from Backlog to In Progress)
     * POST /api/projects/{projectId}/tasks/{taskId}/start
     */
    public function startTask(Request $request, int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);
            $userId = (int)($request->user()->user_id ?? 0);

            // If no assignee, assign to current user
            if (!$task->assigned_to_user_id) {
                // Check WIP limit for current user
                if (!ProjectTaskService::canStartNewTask($projectId, $userId)) {
                    return response()->json([
                        'error' => 'You have reached WIP limit (3 active tasks)',
                        'wip_slots' => ProjectTaskService::getWipSlots($projectId, $userId),
                    ], 422);
                }

                $task->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => now(),
                ]);
            } else {
                // Check WIP limit for assigned user
                if (!ProjectTaskService::canStartNewTask($projectId, $task->assigned_to_user_id)) {
                    return response()->json([
                        'error' => 'Assigned user has reached WIP limit (3 active tasks)',
                        'wip_slots' => ProjectTaskService::getWipSlots($projectId, $task->assigned_to_user_id),
                    ], 422);
                }
            }

            $task = ProjectTaskService::startTask($task);

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to start task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to start task'], 500);
        }
    }

    /**
     * Complete a task (mark as Done)
     * POST /api/projects/{projectId}/tasks/{taskId}/complete
     */
    public function completeTask(int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

            $task = ProjectTaskService::completeTask($task);

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to complete task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to complete task'], 500);
        }
    }

    /**
     * Reopen a completed task (move from Done back to Backlog)
     * POST /api/projects/{projectId}/tasks/{taskId}/reopen
     */
    public function reopenTask(int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

            $task = ProjectTaskService::reopenTask($task);

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reopen task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to reopen task'], 500);
        }
    }


    /**
     * Unassign a task
     * POST /api/projects/{projectId}/tasks/{taskId}/unassign
     */
    public function unassignTask(int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

            $task = ProjectTaskService::unassignTask($task);

            return response()->json([
                'success' => true,
                'data' => $task->load(['createdBy', 'assignedTo']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to un assign task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to unassign task'], 500);
        }
    }

    /**
     * Delete a task
     * DELETE /api/projects/{projectId}/tasks/{taskId}
     */
    public function destroy(int $projectId, int $taskId): JsonResponse
    {
        try {
            $task = ProjectTask::forProject($projectId)->findOrFail($taskId);

            ProjectTaskService::deleteTask($task);

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete task', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to delete task'], 500);
        }
    }

    /**
     * Get task statistics for a project
     * GET /api/projects/{projectId}/tasks/stats
     */
    public function getStats(int $projectId): JsonResponse
    {
        try {
            $stats = ProjectTaskService::getTaskStats($projectId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get task stats', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to get task statistics'], 500);
        }
    }
}

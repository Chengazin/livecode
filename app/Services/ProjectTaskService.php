<?php

namespace App\Services;

use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;

class ProjectTaskService
{
    // WIP Limit configuration
    private const WIP_LIMIT = 3;

    /**
     * Create a new task
     */
    public static function createTask(
        int $projectId,
        int $createdByUserId,
        string $title,
        ?string $description = null,
        int $priority = ProjectTask::PRIORITY_MEDIUM,
        ?\DateTime $dueDate = null
    ): ProjectTask {
        $task = ProjectTask::query()->create([
            'project_id' => $projectId,
            'created_by_user_id' => $createdByUserId,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'due_date' => $dueDate,
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        Log::info('Project task created', [
            'project_task_id' => $task->project_task_id,
            'project_id' => $projectId,
            'created_by' => $createdByUserId,
        ]);

        return $task;
    }

    /**
     * Update a task
     */
    public static function updateTask(
        ProjectTask $task,
        array $data
    ): ProjectTask {
        $allowedFields = ['title', 'description', 'priority', 'due_date', 'status'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (!empty($updateData)) {
            $task->update($updateData);
            Log::info('Project task updated', [
                'project_task_id' => $task->project_task_id,
                'updated_fields' => array_keys($updateData),
            ]);
        }

        return $task;
    }

    /**
     * Assign task to a user
     * If task is in Backlog and has no assignee, moves it to In Progress
     */
    public static function assignTaskToUser(ProjectTask $task, int $userId): ProjectTask
    {
        $task->assignTo($userId);

        // If moving to In Progress, check WIP limit
        if ($task->status === ProjectTask::STATUS_IN_PROGRESS) {
            if (!self::canStartNewTask($task->project_id, $userId)) {
                // Revert back to Backlog with assignee
                $task->update(['status' => ProjectTask::STATUS_BACKLOG]);
                throw new \Exception(sprintf(
                    'User has reached WIP limit (%d active tasks).',
                    self::WIP_LIMIT
                ));
            }
        }

        // Notify the assigned user
        NotificationService::sendNotification(
            userId: $userId,
            type: 'task_assigned',
            title: 'New Task Assigned',
            message: sprintf('Task "%s" has been assigned to you', $task->title),
            data: [
                'project_id' => $task->project_id,
                'project_task_id' => $task->project_task_id,
                'task_title' => $task->title,
            ]
        );

        Log::info('Project task assigned', [
            'project_task_id' => $task->project_task_id,
            'assigned_to' => $userId,
        ]);

        return $task;
    }

    /**
     * Start working on a task (move from Backlog to In Progress)
     * Respects WIP Limit and requires active assignment
     */
    public static function startTask(ProjectTask $task): ProjectTask
    {
        // Check WIP limit for assigned user
        if ($task->assigned_to_user_id) {
            $canStart = self::canStartNewTask($task->project_id, $task->assigned_to_user_id);
            if (!$canStart) {
                throw new \Exception(sprintf(
                    'User has reached WIP limit (%d active tasks). Cannot start more tasks.',
                    self::WIP_LIMIT
                ));
            }
        }

        $task->startWork();

        Log::info('Project task started', [
            'project_task_id' => $task->project_task_id,
            'assigned_to' => $task->assigned_to_user_id,
        ]);

        return $task;
    }

    /**
     * Mark task as done
     */
    public static function completeTask(ProjectTask $task): ProjectTask
    {
        $task->markDone();

        // Notify task creator
        NotificationService::sendNotification(
            userId: $task->created_by_user_id,
            type: 'task_completed',
            title: 'Task Completed',
            message: sprintf('Task "%s" has been completed', $task->title),
            data: [
                'project_id' => $task->project_id,
                'project_task_id' => $task->project_task_id,
            ]
        );

        Log::info('Project task completed', [
            'project_task_id' => $task->project_task_id,
            'completed_by' => $task->assigned_to_user_id,
        ]);

        return $task;
    }

    /**
     * Reopen a completed task (return to Backlog)
     */
    public static function reopenTask(ProjectTask $task): ProjectTask
    {
        $task->reopen();

        // Notify task creator that task was reopened
        NotificationService::sendNotification(
            userId: $task->created_by_user_id,
            type: 'task_reopened',
            title: 'Task Reopened',
            message: sprintf('Task "%s" has been reopened', $task->title),
            data: [
                'project_id' => $task->project_id,
                'project_task_id' => $task->project_task_id,
            ]
        );

        Log::info('Project task reopened', [
            'project_task_id' => $task->project_task_id,
        ]);

        return $task;
    }

    /**
     * Unassign a task
     */
    public static function unassignTask(ProjectTask $task): ProjectTask
    {
        $previousAssignee = $task->assigned_to_user_id;
        $task->unassign();

        // Notify the previously assigned user
        if ($previousAssignee) {
            NotificationService::sendNotification(
                userId: $previousAssignee,
                type: 'task_unassigned',
                title: 'Task Unassigned',
                message: sprintf('Task "%s" has been unassigned from you', $task->title),
                data: [
                    'project_id' => $task->project_id,
                    'project_task_id' => $task->project_task_id,
                ]
            );
        }

        Log::info('Project task unassigned', [
            'project_task_id' => $task->project_task_id,
            'previous_assignee' => $previousAssignee,
        ]);

        return $task;
    }

    /**
     * Delete a task
     */
    public static function deleteTask(ProjectTask $task): bool
    {
        $taskId = $task->project_task_id;
        $task->delete();

        Log::info('Project task deleted', [
            'project_task_id' => $taskId,
        ]);

        return true;
    }

    /**
     * Get tasks for a project
     */
    public static function getProjectTasks(
        int $projectId,
        ?string $status = null,
        ?int $assignedToUserId = null,
        ?string $sortBy = 'due_date',
        int $perPage = 15
    ) {
        $query = ProjectTask::query()
            ->forProject($projectId)
            ->with(['createdBy', 'assignedTo']);

        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }

        // Filter by assignee
        if ($assignedToUserId) {
            $query->where('assigned_to_user_id', $assignedToUserId);
        }

        // Sort by priority desc, then due_date, then created_at desc
        if ($sortBy === 'priority') {
            $query->orderByDesc('priority');
        } elseif ($sortBy === 'due_date') {
            $query->orderByRaw('COALESCE(due_date, created_at) ASC');
        } elseif ($sortBy === 'created') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('priority')
                ->orderByRaw('COALESCE(due_date, created_at) ASC');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get overdue tasks for a project
     */
    public static function getOverdueTasks(int $projectId): Collection
    {
        return ProjectTask::query()
            ->forProject($projectId)
            ->overdue()
            ->with(['createdBy', 'assignedTo'])
            ->orderByDesc('priority')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Get user's assigned tasks for a project
     */
    public static function getUserProjectTasks(int $projectId, int $userId): Collection
    {
        return ProjectTask::query()
            ->forProject($projectId)
            ->assignedTo($userId)
            ->with(['createdBy', 'project'])
            ->get();
    }

    /**
     * Get active (in progress) task count for a user in a project
     */
    public static function getActiveTasksCount(int $projectId, int $userId): int
    {
        return ProjectTask::query()
            ->forProject($projectId)
            ->active()
            ->assignedTo($userId)
            ->count();
    }

    /**
     * Check if user can start a new task (respects WIP Limit)
     */
    public static function canStartNewTask(int $projectId, int $userId): bool
    {
        return self::getActiveTasksCount($projectId, $userId) < self::WIP_LIMIT;
    }

    /**
     * Get remaining WIP slots for a user
     */
    public static function getWipSlots(int $projectId, int $userId): int
    {
        return max(0, self::WIP_LIMIT - self::getActiveTasksCount($projectId, $userId));
    }

    /**
     * Get task statistics for a project
     */
    public static function getTaskStats(int $projectId): array
    {
        return [
            'total' => ProjectTask::forProject($projectId)->count(),
            'backlog' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_BACKLOG)->count(),
            'in_progress' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_IN_PROGRESS)->count(),
            'done' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_DONE)->count(),
            'unassigned' => ProjectTask::forProject($projectId)->whereNull('assigned_to_user_id')->count(),
            'overdue' => ProjectTask::forProject($projectId)->overdue()->count(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;

class ProjectTaskService
{
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
            'status' => ProjectTask::STATUS_OPEN,
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
     */
    public static function assignTaskToUser(ProjectTask $task, int $userId): ProjectTask
    {
        $task->assignTo($userId);

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
     * Start working on a task
     */
    public static function startTask(ProjectTask $task): ProjectTask
    {
        $task->startWork();

        // Notify task creator
        NotificationService::sendNotification(
            userId: $task->created_by_user_id,
            type: 'task_started',
            title: 'Task In Progress',
            message: sprintf('Task "%s" has been started', $task->title),
            data: [
                'project_id' => $task->project_id,
                'project_task_id' => $task->project_task_id,
            ]
        );

        Log::info('Project task started', [
            'project_task_id' => $task->project_task_id,
            'started_by' => $task->assigned_to_user_id,
        ]);

        return $task;
    }

    /**
     * Complete a task
     */
    public static function completeTask(ProjectTask $task): ProjectTask
    {
        $task->complete();

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
     * Close a task
     */
    public static function closeTask(ProjectTask $task): ProjectTask
    {
        $task->close();
        Log::info('Project task closed', [
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
            ->orderByDesc('priority')
            ->orderByRaw('COALESCE(due_date, created_at) ASC')
            ->get();
    }

    /**
     * Get task statistics for a project
     */
    public static function getTaskStats(int $projectId): array
    {
        return [
            'total' => ProjectTask::forProject($projectId)->count(),
            'open' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_OPEN)->count(),
            'assigned' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_ASSIGNED)->count(),
            'in_progress' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_IN_PROGRESS)->count(),
            'completed' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_COMPLETED)->count(),
            'closed' => ProjectTask::forProject($projectId)->where('status', ProjectTask::STATUS_CLOSED)->count(),
            'overdue' => ProjectTask::forProject($projectId)->overdue()->count(),
        ];
    }
}

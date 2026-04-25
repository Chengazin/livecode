<?php

namespace App\Services\ProjectTask;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\ProjectTaskService;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;

class ProjectTaskWorkflowService
{
    public function list(
        int $projectId,
        ?string $status,
        ?int $assignedToUserId,
        string $sortBy,
        int $perPage
    ): LengthAwarePaginator {
        return ProjectTaskService::getProjectTasks(
            $projectId,
            $status,
            $assignedToUserId,
            $sortBy,
            $perPage
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $projectId, User $user, array $data): ProjectTaskWorkflowResult
    {
        $task = ProjectTaskService::createTask(
            $projectId,
            (int) $user->user_id,
            (string) $data['title'],
            isset($data['description']) ? (string) $data['description'] : null,
            (int) ($data['priority'] ?? ProjectTask::PRIORITY_MEDIUM),
            isset($data['due_date']) ? new \DateTimeImmutable((string) $data['due_date']) : null
        );

        return new ProjectTaskWorkflowResult(201, [
            'success' => true,
            'data' => $task->load(['createdBy', 'assignedTo']),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(ProjectTask $task, array $data): ProjectTaskWorkflowResult
    {
        try {
            $updated = ProjectTaskService::updateTask($task, $data);
        } catch (DomainException $exception) {
            return new ProjectTaskWorkflowResult(422, [
                'error' => $exception->getMessage(),
            ]);
        }

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'data' => $updated->load(['createdBy', 'assignedTo']),
        ]);
    }

    public function assign(
        Project $project,
        ProjectTask $task,
        int $assigneeUserId,
        ProjectAccessService $access
    ): ProjectTaskWorkflowResult {
        $assignee = User::query()->findOrFail($assigneeUserId);

        if (! $access->userHasAccess($project, $assignee)) {
            return new ProjectTaskWorkflowResult(422, [
                'error' => 'Assignee must have access to the project',
            ]);
        }

        try {
            $updated = ProjectTaskService::assignTaskToUser($task, $assigneeUserId);
        } catch (DomainException $exception) {
            return new ProjectTaskWorkflowResult(422, [
                'error' => $exception->getMessage(),
                'wip_slots' => ProjectTaskService::getWipSlots((int) $project->project_id, $assigneeUserId),
            ]);
        }

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'data' => $updated->load(['createdBy', 'assignedTo']),
        ]);
    }

    public function start(
        Project $project,
        ProjectTask $task,
        User $user,
        ProjectAccessService $access
    ): ProjectTaskWorkflowResult {
        if (! $access->canTakeTasks($project, $user)) {
            return new ProjectTaskWorkflowResult(403, ['message' => 'Access denied.']);
        }

        $currentUserId = (int) $user->user_id;
        $assignedToUserId = (int) ($task->assigned_to_user_id ?: 0);
        $canManageTasks = $access->canManageTasks($project, $user);

        if (! $canManageTasks && $assignedToUserId !== 0 && $assignedToUserId !== $currentUserId) {
            return new ProjectTaskWorkflowResult(422, [
                'error' => 'Task is assigned to another user.',
            ]);
        }

        if (! $task->assigned_to_user_id) {
            if (! ProjectTaskService::canStartNewTask((int) $project->project_id, $currentUserId)) {
                return new ProjectTaskWorkflowResult(422, [
                    'error' => 'You have reached WIP limit (3 active tasks)',
                    'wip_slots' => ProjectTaskService::getWipSlots((int) $project->project_id, $currentUserId),
                ]);
            }

            $task->update([
                'assigned_to_user_id' => $currentUserId,
                'assigned_at' => now(),
            ]);
        }

        try {
            $updated = ProjectTaskService::startTask($task);
        } catch (DomainException $exception) {
            $targetUserId = (int) ($task->assigned_to_user_id ?: $currentUserId);

            return new ProjectTaskWorkflowResult(422, [
                'error' => $exception->getMessage(),
                'wip_slots' => ProjectTaskService::getWipSlots((int) $project->project_id, $targetUserId),
            ]);
        }

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'data' => $updated->load(['createdBy', 'assignedTo']),
        ]);
    }

    public function complete(ProjectTask $task): ProjectTaskWorkflowResult
    {
        try {
            $updated = ProjectTaskService::completeTask($task);
        } catch (DomainException $exception) {
            return new ProjectTaskWorkflowResult(422, ['error' => $exception->getMessage()]);
        }

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'data' => $updated->load(['createdBy', 'assignedTo']),
        ]);
    }

    public function reopen(ProjectTask $task): ProjectTaskWorkflowResult
    {
        try {
            $updated = ProjectTaskService::reopenTask($task);
        } catch (DomainException $exception) {
            return new ProjectTaskWorkflowResult(422, ['error' => $exception->getMessage()]);
        }

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'data' => $updated->load(['createdBy', 'assignedTo']),
        ]);
    }

    public function unassign(ProjectTask $task): ProjectTaskWorkflowResult
    {
        $updated = ProjectTaskService::unassignTask($task);

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'data' => $updated->load(['createdBy', 'assignedTo']),
        ]);
    }

    public function delete(ProjectTask $task): ProjectTaskWorkflowResult
    {
        ProjectTaskService::deleteTask($task);

        return new ProjectTaskWorkflowResult(200, [
            'success' => true,
            'message' => 'Task deleted successfully',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(int $projectId): array
    {
        return ProjectTaskService::getTaskStats($projectId);
    }
}

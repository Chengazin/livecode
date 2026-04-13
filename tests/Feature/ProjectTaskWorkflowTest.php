<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectParticipant;
use App\Models\User;
use App\Services\ProjectTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        // Create test project
        $this->project = Project::factory()->create([
            'owner_id' => $this->user->user_id,
        ]);

        // Add participants
        ProjectParticipant::create([
            'project_id' => $this->project->project_id,
            'user_id' => $this->user->user_id,
            'role' => 'admin',
        ]);

        ProjectParticipant::create([
            'project_id' => $this->project->project_id,
            'user_id' => $this->otherUser->user_id,
            'role' => 'member',
        ]);
    }

    /** @test */
    public function task_starts_in_backlog_status()
    {
        $task = ProjectTaskService::createTask(
            projectId: $this->project->project_id,
            createdByUserId: $this->user->user_id,
            title: 'Test Task',
            description: 'Test Description',
            priority: ProjectTask::PRIORITY_MEDIUM,
        );

        $this->assertEquals(ProjectTask::STATUS_BACKLOG, $task->status);
        $this->assertNull($task->assigned_to_user_id);
    }

    /** @test */
    public function task_moves_to_in_progress_when_assigned()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Test Task',
            'status' => ProjectTask::STATUS_BACKLOG,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        // Assign task
        $assignedTask = ProjectTaskService::assignTaskToUser($task, $this->otherUser->user_id);

        $this->assertEquals(ProjectTask::STATUS_IN_PROGRESS, $assignedTask->status);
        $this->assertEquals($this->otherUser->user_id, $assignedTask->assigned_to_user_id);
    }

    /** @test */
    public function task_moves_to_done_when_completed()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Test Task',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        // Complete task
        $completedTask = ProjectTaskService::completeTask($task);

        $this->assertEquals(ProjectTask::STATUS_DONE, $completedTask->status);
        $this->assertNotNull($completedTask->completed_at);
    }

    /** @test */
    public function task_can_be_reopened_from_done()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Test Task',
            'status' => ProjectTask::STATUS_DONE,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
            'completed_at' => now(),
        ]);

        // Reopen task
        $reopenedTask = ProjectTaskService::reopenTask($task);

        $this->assertEquals(ProjectTask::STATUS_BACKLOG, $reopenedTask->status);
        $this->assertNull($reopenedTask->completed_at);
    }

    /** @test */
    public function wip_limit_prevents_assigning_more_than_three_tasks()
    {
        // Create 3 in-progress tasks for the user
        for ($i = 0; $i < 3; $i++) {
            ProjectTask::create([
                'project_id' => $this->project->project_id,
                'created_by_user_id' => $this->user->user_id,
                'assigned_to_user_id' => $this->otherUser->user_id,
                'title' => "Task {$i}",
                'status' => ProjectTask::STATUS_IN_PROGRESS,
                'priority' => ProjectTask::PRIORITY_MEDIUM,
            ]);
        }

        // Verify user has 3 active tasks
        $activeCount = ProjectTaskService::getActiveTasksCount(
            $this->project->project_id,
            $this->otherUser->user_id
        );
        $this->assertEquals(3, $activeCount);

        // Verify WIP check returns false (cannot start new task)
        $canStart = ProjectTaskService::canStartNewTask(
            $this->project->project_id,
            $this->otherUser->user_id
        );
        $this->assertFalse($canStart);

        // Try to assign a 4th task - should throw exception
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Fourth Task',
            'status' => ProjectTask::STATUS_BACKLOG,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/WIP limit/');

        ProjectTaskService::assignTaskToUser($task, $this->otherUser->user_id);
    }

    /** @test */
    public function wip_slots_calculation_is_correct()
    {
        // Create 2 in-progress tasks
        for ($i = 0; $i < 2; $i++) {
            ProjectTask::create([
                'project_id' => $this->project->project_id,
                'created_by_user_id' => $this->user->user_id,
                'assigned_to_user_id' => $this->otherUser->user_id,
                'title' => "Task {$i}",
                'status' => ProjectTask::STATUS_IN_PROGRESS,
                'priority' => ProjectTask::PRIORITY_MEDIUM,
            ]);
        }

        // User should have 1 remaining WIP slot
        $slots = ProjectTaskService::getWipSlots(
            $this->project->project_id,
            $this->otherUser->user_id
        );
        $this->assertEquals(1, $slots);
    }

    /** @test */
    public function unassigning_task_returns_it_to_backlog()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Test Task',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        // Unassign task
        $unassignedTask = ProjectTaskService::unassignTask($task);

        $this->assertEquals(ProjectTask::STATUS_BACKLOG, $unassignedTask->status);
        $this->assertNull($unassignedTask->assigned_to_user_id);
    }

    /** @test */
    public function task_stats_returns_correct_counts()
    {
        // Create tasks in different statuses
        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Backlog Task 1',
            'status' => ProjectTask::STATUS_BACKLOG,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'In Progress Task',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Done Task',
            'status' => ProjectTask::STATUS_DONE,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        $stats = ProjectTaskService::getTaskStats($this->project->project_id);

        $this->assertEquals(1, $stats['backlog']);
        $this->assertEquals(1, $stats['in_progress']);
        $this->assertEquals(1, $stats['done']);
    }
}

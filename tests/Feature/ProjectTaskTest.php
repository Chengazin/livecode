<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private User $outsiderUser;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->outsiderUser = User::factory()->create();
        $this->project = Project::factory()->create(['owner_id' => $this->user->user_id]);

        $this->project->users()->attach($this->user->user_id, ['role' => 'maintainer']);
        $this->project->users()->attach($this->otherUser->user_id, ['role' => 'developer']);
    }

    public function test_create_task()
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks",
            [
                'title' => 'Implement new feature',
                'description' => 'Add user authentication',
                'priority' => ProjectTask::PRIORITY_HIGH,
                'due_date' => date('Y-m-d', strtotime('+7 days')),
            ]
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Implement new feature')
            ->assertJsonPath('data.status', ProjectTask::STATUS_BACKLOG)
            ->assertJsonPath('data.created_by_user_id', $this->user->user_id);

        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $this->project->project_id,
            'title' => 'Implement new feature',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);
    }

    public function test_get_project_tasks()
    {
        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task 1',
            'status' => ProjectTask::STATUS_BACKLOG,
            'priority' => ProjectTask::PRIORITY_HIGH,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task 2',
            'assigned_to_user_id' => $this->otherUser->user_id,
            'status' => ProjectTask::STATUS_IN_PROGRESS,
            'priority' => ProjectTask::PRIORITY_MEDIUM,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 2);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_filter_tasks_by_status()
    {
        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Backlog Task',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'In Progress Task',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks?status=" . ProjectTask::STATUS_BACKLOG
        );

        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1);

        $this->assertEquals('Backlog Task', $response->json('data.0.title'));
    }

    public function test_assign_task_to_user()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task to Assign',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/assign",
            ['user_id' => $this->otherUser->user_id]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.assigned_to_user_id', $this->otherUser->user_id)
            ->assertJsonPath('data.status', ProjectTask::STATUS_IN_PROGRESS);

        $this->assertNotNull($response->json('data.assigned_at'));
    }

    public function test_start_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task to Start',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/start"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', ProjectTask::STATUS_IN_PROGRESS);

        $this->assertNotNull($response->json('data.started_at'));
    }

    public function test_complete_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task to Complete',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/complete"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', ProjectTask::STATUS_DONE);

        $this->assertNotNull($response->json('data.completed_at'));
    }

    public function test_reopen_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task to Reopen',
            'status' => ProjectTask::STATUS_DONE,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/reopen"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', ProjectTask::STATUS_BACKLOG)
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_unassign_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task to Unassign',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/unassign"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.assigned_to_user_id', null)
            ->assertJsonPath('data.status', ProjectTask::STATUS_BACKLOG);
    }

    public function test_delete_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task to Delete',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('project_tasks', [
            'project_task_id' => $task->project_task_id,
        ]);
    }

    public function test_get_task_stats()
    {
        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Backlog Task',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'In Progress Task',
            'assigned_to_user_id' => $this->otherUser->user_id,
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Done Task',
            'assigned_to_user_id' => $this->otherUser->user_id,
            'status' => ProjectTask::STATUS_DONE,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks/stats"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.backlog', 1)
            ->assertJsonPath('data.in_progress', 1)
            ->assertJsonPath('data.done', 1);
    }

    public function test_get_user_assigned_tasks()
    {
        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task for Other User',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Unassigned Task',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks?assigned_to_user_id={$this->otherUser->user_id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1);

        $this->assertEquals($this->otherUser->user_id, $response->json('data.0.assigned_to_user_id'));
    }

    public function test_update_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Original Title',
            'priority' => ProjectTask::PRIORITY_LOW,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->patchJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}",
            [
                'title' => 'Updated Title',
                'priority' => ProjectTask::PRIORITY_HIGH,
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.priority', ProjectTask::PRIORITY_HIGH);
    }

    public function test_get_single_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Single Task',
            'description' => 'Task description',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Single Task')
            ->assertJsonPath('data.description', 'Task description');
    }

    public function test_start_unassigned_task_claims_it_for_current_user()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Unassigned Task',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/start"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', ProjectTask::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.assigned_to_user_id', $this->user->user_id);
    }

    public function test_non_participant_cannot_view_project_tasks()
    {
        $response = $this->actingAs($this->outsiderUser, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks"
        );

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_cannot_assign_task_to_user_without_project_access()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task to Assign',
            'status' => ProjectTask::STATUS_BACKLOG,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/assign",
            ['user_id' => $this->outsiderUser->user_id]
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Assignee must have access to the project');
    }
}

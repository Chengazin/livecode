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
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->project = Project::factory()->create(['owner_id' => $this->user->user_id]);

        // Add users as participants via the users() relationship
        $this->project->users()->attach($this->user->user_id, ['role' => 'owner']);
        $this->project->users()->attach($this->otherUser->user_id, ['role' => 'maintainer']);
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
            ->assertJsonPath('data.status', ProjectTask::STATUS_OPEN)
            ->assertJsonPath('data.created_by_user_id', $this->user->user_id);

        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $this->project->project_id,
            'title' => 'Implement new feature',
        ]);
    }

    public function test_get_project_tasks()
    {
        $task1 = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task 1',
            'status' => ProjectTask::STATUS_OPEN,
            'priority' => ProjectTask::PRIORITY_HIGH,
        ]);

        $task2 = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task 2',
            'assigned_to_user_id' => $this->otherUser->user_id,
            'status' => ProjectTask::STATUS_ASSIGNED,
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
            'title' => 'Open Task',
            'status' => ProjectTask::STATUS_OPEN,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'In Progress Task',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks?status=" . ProjectTask::STATUS_OPEN
        );

        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1);

        $this->assertEquals('Open Task', $response->json('data.0.title'));
    }

    public function test_assign_task_to_user()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task to Assign',
            'status' => ProjectTask::STATUS_OPEN,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/assign",
            ['user_id' => $this->otherUser->user_id]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.assigned_to_user_id', $this->otherUser->user_id)
            ->assertJsonPath('data.status', ProjectTask::STATUS_ASSIGNED);

        $this->assertNotNull($response->json('data.assigned_at'));
    }

    public function test_start_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task to Start',
            'status' => ProjectTask::STATUS_ASSIGNED,
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
            ->assertJsonPath('data.status', ProjectTask::STATUS_COMPLETED);

        $this->assertNotNull($response->json('data.completed_at'));
    }

    public function test_unassign_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task to Unassign',
            'status' => ProjectTask::STATUS_ASSIGNED,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/unassign"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.assigned_to_user_id', null)
            ->assertJsonPath('data.status', ProjectTask::STATUS_OPEN);
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
            'title' => 'Task 1',
            'status' => ProjectTask::STATUS_OPEN,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task 2',
            'assigned_to_user_id' => $this->otherUser->user_id,
            'status' => ProjectTask::STATUS_ASSIGNED,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Task 3',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/tasks/stats"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.open', 1)
            ->assertJsonPath('data.assigned', 1)
            ->assertJsonPath('data.in_progress', 1);
    }

    public function test_get_user_assigned_tasks()
    {
        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'assigned_to_user_id' => $this->otherUser->user_id,
            'title' => 'Task for Other User',
            'status' => ProjectTask::STATUS_ASSIGNED,
        ]);

        ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Unassigned Task',
            'status' => ProjectTask::STATUS_OPEN,
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

    public function test_cannot_start_unassigned_task()
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->project_id,
            'created_by_user_id' => $this->user->user_id,
            'title' => 'Unassigned Task',
            'status' => ProjectTask::STATUS_OPEN,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/tasks/{$task->project_task_id}/start"
        );

        $response->assertStatus(400)
            ->assertJsonPath('error', 'Task must be assigned before starting');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectFilesystemAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $developer;
    private User $viewer;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->developer = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->user_id,
        ]);

        $this->project->users()->attach($this->developer->user_id, ['role' => 'developer']);
        $this->project->users()->attach($this->viewer->user_id, ['role' => 'viewer']);

        Storage::disk('local')->makeDirectory($this->project->project_path);
    }

    public function test_developer_can_mutate_project_filesystem(): void
    {
        $create = $this->actingAs($this->developer, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/filesystem",
            [
                'action' => 'create_file',
                'path' => 'src/dev.txt',
                'content' => 'hello',
            ]
        );

        $create->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('path', 'src/dev.txt');

        $write = $this->actingAs($this->developer, 'sanctum')->putJson(
            "/api/projects/{$this->project->project_id}/filesystem/file",
            [
                'path' => 'src/dev.txt',
                'content' => 'updated',
            ]
        );

        $write->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('path', 'src/dev.txt');

        $move = $this->actingAs($this->developer, 'sanctum')->putJson(
            "/api/projects/{$this->project->project_id}/filesystem/move",
            [
                'from_path' => 'src/dev.txt',
                'to_path' => 'src/dev-renamed.txt',
            ]
        );

        $move->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('to_path', 'src/dev-renamed.txt');

        $delete = $this->actingAs($this->developer, 'sanctum')->deleteJson(
            "/api/projects/{$this->project->project_id}/filesystem/item",
            [
                'path' => 'src/dev-renamed.txt',
            ]
        );

        $delete->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('path', 'src/dev-renamed.txt');
    }

    public function test_viewer_has_read_only_filesystem_access(): void
    {
        Storage::disk('local')->put($this->project->project_path.'/README.md', 'hello');

        $this->actingAs($this->viewer, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/filesystem/tree"
        )->assertOk();

        $this->actingAs($this->viewer, 'sanctum')->getJson(
            "/api/projects/{$this->project->project_id}/filesystem/file?path=README.md"
        )->assertOk()->assertJsonPath('path', 'README.md');

        $this->actingAs($this->viewer, 'sanctum')->postJson(
            "/api/projects/{$this->project->project_id}/filesystem",
            [
                'action' => 'create_file',
                'path' => 'viewer.txt',
                'content' => 'denied',
            ]
        )->assertStatus(403)->assertJsonPath('error', 'access_denied');

        $this->actingAs($this->viewer, 'sanctum')->putJson(
            "/api/projects/{$this->project->project_id}/filesystem/file",
            [
                'path' => 'README.md',
                'content' => 'mutated',
            ]
        )->assertStatus(403)->assertJsonPath('error', 'access_denied');

        $this->actingAs($this->viewer, 'sanctum')->putJson(
            "/api/projects/{$this->project->project_id}/filesystem/move",
            [
                'from_path' => 'README.md',
                'to_path' => 'README-2.md',
            ]
        )->assertStatus(403)->assertJsonPath('error', 'access_denied');

        $this->actingAs($this->viewer, 'sanctum')->deleteJson(
            "/api/projects/{$this->project->project_id}/filesystem/item",
            [
                'path' => 'README.md',
            ]
        )->assertStatus(403)->assertJsonPath('error', 'access_denied');
    }
}

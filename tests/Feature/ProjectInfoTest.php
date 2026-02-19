<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\ProjectSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_info_returns_participants_stats_and_permissions(): void
    {
        $owner = $this->createUser('owner-info@example.com');
        $collaborator = $this->createUser('collab-info@example.com');

        $project = Project::query()->create([
            'name' => 'Info project',
            'description' => 'stats and history',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/info-project',
            'is_public' => false,
        ]);

        $participant = ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $collaborator->user_id,
            'role' => ProjectParticipant::ROLE_VIEWER,
        ]);

        ProjectSnapshot::query()->create([
            'project_id' => $project->project_id,
            'snapshot_hash' => str_repeat('a', 64),
            'author_user_id' => $owner->user_id,
            'message' => 'Owner update',
            'snapshot_path' => '/tmp/a.zip',
            'size_bytes' => 128,
            'created_at' => now()->subDays(1),
        ]);

        ProjectSnapshot::query()->create([
            'project_id' => $project->project_id,
            'snapshot_hash' => str_repeat('b', 64),
            'author_user_id' => $collaborator->user_id,
            'message' => 'Collaborator update',
            'snapshot_path' => '/tmp/b.zip',
            'size_bytes' => 256,
            'created_at' => now()->subDays(2),
        ]);

        ProjectSnapshot::query()->create([
            'project_id' => $project->project_id,
            'snapshot_hash' => str_repeat('c', 64),
            'author_user_id' => $collaborator->user_id,
            'message' => 'Old update',
            'snapshot_path' => '/tmp/c.zip',
            'size_bytes' => 256,
            'created_at' => now()->subDays(70),
        ]);

        $token = $collaborator->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->project_id.'/info?period_days=30');

        $response
            ->assertOk()
            ->assertJsonPath('project.project_id', $project->project_id)
            ->assertJsonPath('permissions.effective_role', ProjectParticipant::ROLE_VIEWER)
            ->assertJsonPath('permissions.is_owner', false)
            ->assertJsonPath('participants.0.participant_id', $participant->participant_id)
            ->assertJsonPath('participants.0.role', ProjectParticipant::ROLE_VIEWER)
            ->assertJsonPath('stats.total_snapshots', 2);

        $response->assertJsonCount(3, 'roles');
    }

    public function test_owner_can_update_participant_role(): void
    {
        $owner = $this->createUser('owner-role@example.com');
        $collaborator = $this->createUser('collab-role@example.com');

        $project = Project::query()->create([
            'name' => 'Role project',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/role-project',
            'is_public' => false,
        ]);

        $participant = ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $collaborator->user_id,
            'role' => ProjectParticipant::ROLE_DEVELOPER,
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/project-participants/'.$participant->participant_id, [
            'role' => ProjectParticipant::ROLE_MAINTAINER,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('role', ProjectParticipant::ROLE_MAINTAINER);

        $this->assertSame(
            ProjectParticipant::ROLE_MAINTAINER,
            (string) $participant->fresh()->role
        );
    }

    public function test_participant_role_validation_rejects_unknown_value(): void
    {
        $owner = $this->createUser('owner-invalid-role@example.com');
        $collaborator = $this->createUser('collab-invalid-role@example.com');

        $project = Project::query()->create([
            'name' => 'Invalid role project',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/invalid-role',
            'is_public' => false,
        ]);

        $participant = ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $collaborator->user_id,
            'role' => ProjectParticipant::ROLE_DEVELOPER,
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/project-participants/'.$participant->participant_id, [
            'role' => 'super-admin',
        ]);

        $response->assertStatus(422);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test user',
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'language' => 'eng',
        ]);
    }
}

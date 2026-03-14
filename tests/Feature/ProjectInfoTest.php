<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use App\Services\ProjectGitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
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

        $token = $collaborator->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->project_id.'/info?period_days=30');

        $response
            ->assertOk()
            ->assertJsonPath('project.project_id', $project->project_id)
            ->assertJsonPath('permissions.effective_role', ProjectParticipant::ROLE_VIEWER)
            ->assertJsonPath('permissions.is_owner', false)
            ->assertJsonPath('participants.0.participant_id', $participant->participant_id)
            ->assertJsonPath('participants.0.role', ProjectParticipant::ROLE_VIEWER);

        $payload = $response->json();
        $this->assertIsArray($payload['stats'] ?? null);
        $this->assertArrayNotHasKey('total_snapshots', $payload['stats']);
        $this->assertIsArray($payload['history'] ?? null);
        $this->assertArrayNotHasKey('snapshots', $payload['history']);

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

    public function test_project_info_fetches_remote_branches_for_connected_repository(): void
    {
        $owner = $this->createUser('owner-remote-branches@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Remote branches',
            'description' => 'graph data',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/remote-branches',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'team/repo';
        $project->forgejo_repo_clone_url = 'http://forgejo:3000/team/repo.git';
        $project->forgejo_default_branch = 'main';
        $project->save();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isRepository')->once()->andReturn(true);
            $mock->shouldReceive('hasChanges')->once()->andReturn(false);
            $mock->shouldReceive('fetchRemoteBranches')
                ->once()
                ->withArgs(function (string $path, string $remoteUrl, ?string $token): bool {
                    return str_contains($path, 'projects')
                        && $remoteUrl === 'http://forgejo:3000/team/repo.git'
                        && $token === 'forgejo-token';
                })
                ->andReturn('From http://forgejo:3000/team/repo');
            $mock->shouldReceive('listBranches')->once()->andReturn([
                [
                    'name' => 'main',
                    'ref_name' => 'main',
                    'is_current' => true,
                    'head_hash' => 'aaa111',
                    'last_commit_at' => now()->subMinute()->toIso8601String(),
                ],
                [
                    'name' => 'feature/api',
                    'ref_name' => 'origin/feature/api',
                    'is_current' => false,
                    'head_hash' => 'bbb222',
                    'last_commit_at' => now()->toIso8601String(),
                ],
            ]);
            $mock->shouldReceive('listCommits')
                ->times(3)
                ->andReturnUsing(function (string $path, int $limit, ?string $ref = null): array {
                    $normalizedRef = trim((string) $ref);

                    if ($normalizedRef === 'origin/feature/api') {
                        return [[
                            'hash' => 'bbb222',
                            'short_hash' => 'bbb222',
                            'parents' => ['aaa111'],
                            'author_name' => 'Owner',
                            'author_email' => 'owner-remote-branches@example.com',
                            'authored_at' => now()->toIso8601String(),
                            'subject' => 'Feature branch commit',
                            'decorations' => 'origin/feature/api',
                        ]];
                    }

                    return [[
                        'hash' => 'aaa111',
                        'short_hash' => 'aaa111',
                        'parents' => [],
                        'author_name' => 'Owner',
                        'author_email' => 'owner-remote-branches@example.com',
                        'authored_at' => now()->subMinute()->toIso8601String(),
                        'subject' => 'Main branch commit',
                        'decorations' => 'main',
                    ]];
                });
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->project_id.'/info');

        $response
            ->assertOk()
            ->assertJsonPath('history.git.tree_available', true)
            ->assertJsonCount(2, 'history.git.branches')
            ->assertJsonFragment(['name' => 'feature/api'])
            ->assertJsonFragment(['ref_name' => 'origin/feature/api']);
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

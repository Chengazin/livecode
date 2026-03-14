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

class ProjectGitActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_developer_can_create_branch_from_commit(): void
    {
        $owner = $this->createUser('owner-git-actions@example.com');
        $developer = $this->createUser('developer-git-actions@example.com');

        $project = Project::query()->create([
            'name' => 'Git actions',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/git-actions',
            'is_public' => false,
        ]);

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $developer->user_id,
            'role' => ProjectParticipant::ROLE_DEVELOPER,
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isRepository')->once()->andReturn(true);
            $mock->shouldReceive('hasChanges')->once()->andReturn(false);
            $mock->shouldReceive('validateBranchName')->once()->withArgs(function (string $path, string $branchName): bool {
                return str_contains($path, 'projects') && $branchName === 'feature/demo';
            });
            $mock->shouldReceive('branchExists')->once()->andReturn(false);
            $mock->shouldReceive('createBranchFromCommit')->once()->withArgs(function (string $path, string $branchName, string $commitHash): bool {
                return str_contains($path, 'projects')
                    && $branchName === 'feature/demo'
                    && $commitHash === 'abc1234';
            });
        });

        $token = $developer->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/git/branch', [
            'branch_name' => 'feature/demo',
            'commit_hash' => 'abc1234',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('action', 'branch_created')
            ->assertJsonPath('branch_name', 'feature/demo')
            ->assertJsonPath('commit_hash', 'abc1234');
    }

    public function test_developer_can_checkout_commit(): void
    {
        $owner = $this->createUser('owner-checkout-commit@example.com');
        $developer = $this->createUser('developer-checkout-commit@example.com');

        $project = Project::query()->create([
            'name' => 'Checkout commit',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/checkout-commit',
            'is_public' => false,
        ]);

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $developer->user_id,
            'role' => ProjectParticipant::ROLE_DEVELOPER,
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isRepository')->once()->andReturn(true);
            $mock->shouldReceive('hasChanges')->once()->andReturn(false);
            $mock->shouldReceive('checkoutCommit')->once()->withArgs(function (string $path, string $commitHash): bool {
                return str_contains($path, 'projects') && $commitHash === 'deadbeef';
            });
        });

        $token = $developer->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/git/checkout-commit', [
            'commit_hash' => 'deadbeef',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('action', 'commit_checked_out')
            ->assertJsonPath('commit_hash', 'deadbeef')
            ->assertJsonPath('detached', true);
    }

    public function test_viewer_cannot_change_project_git_state(): void
    {
        $owner = $this->createUser('owner-viewer-git@example.com');
        $viewer = $this->createUser('viewer-git@example.com');

        $project = Project::query()->create([
            'name' => 'Viewer Git',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/viewer-git',
            'is_public' => false,
        ]);

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $viewer->user_id,
            'role' => ProjectParticipant::ROLE_VIEWER,
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('isRepository');
            $mock->shouldNotReceive('hasChanges');
        });

        $token = $viewer->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/git/checkout-branch', [
            'branch_name' => 'main',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Project is read-only for your role.');
    }

    public function test_checkout_branch_requires_clean_worktree(): void
    {
        $owner = $this->createUser('owner-dirty-worktree@example.com');

        $project = Project::query()->create([
            'name' => 'Dirty worktree',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/dirty-worktree',
            'is_public' => false,
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isRepository')->once()->andReturn(true);
            $mock->shouldReceive('hasChanges')->once()->andReturn(true);
            $mock->shouldNotReceive('checkoutBranch');
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/git/checkout-branch', [
            'branch_name' => 'main',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('message', 'Commit or stash local changes before switching project Git state.');
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

<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use App\Services\ProjectGitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class ForgejoSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_callback_requires_authenticated_user(): void
    {
        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

        Cache::put('forgejo_oauth_state:test-state', [
            'mode' => 'connect',
            'user_id' => 999,
            'created_at' => now()->toISOString(),
            'binding' => 'test-binding',
        ], now()->addMinutes(10));

        $response = $this
            ->withHeader('X-Forgejo-OAuth-Binding', 'test-binding')
            ->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthorized.',
            ]);
    }

    public function test_connect_callback_rejects_state_user_mismatch(): void
    {
        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

        $stateOwner = $this->createUser('state-owner@example.com');
        $anotherUser = $this->createUser('other@example.com');

        Cache::put('forgejo_oauth_state:test-state', [
            'mode' => 'connect',
            'user_id' => $stateOwner->user_id,
            'created_at' => now()->toISOString(),
            'binding' => 'test-binding',
        ], now()->addMinutes(10));

        $token = $anotherUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Forgejo-OAuth-Binding', 'test-binding')
            ->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'State user mismatch.',
            ]);
    }

    public function test_callback_rejects_request_without_state_binding_cookie(): void
    {
        Cache::put('forgejo_oauth_state:test-state', [
            'mode' => 'login',
            'user_id' => null,
            'created_at' => now()->toISOString(),
            'binding' => 'required-cookie-binding',
        ], now()->addMinutes(10));

        $response = $this->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid state.',
            ]);
    }

    public function test_start_connect_allows_sanctum_authenticated_user(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.git_base_url' => 'https://forgejo.example.test',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $user = $this->createUser('connect-user@example.com');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/forgejo/oauth/start', [
            'mode' => 'connect',
        ]);

        $response->assertOk();
        $response->assertJsonPath('mode', 'connect');
        $this->assertIsString($response->json('state'));
        $this->assertStringContainsString(
            '/login/oauth/authorize',
            (string) $response->json('auth_url')
        );
    }

    public function test_start_connect_uses_public_forgejo_url_for_auth_redirect(): void
    {
        config([
            'services.forgejo.base_url' => 'http://forgejo.internal:3000',
            'services.forgejo.public_url' => 'http://localhost:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $user = $this->createUser('connect-public-url@example.com');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/forgejo/oauth/start', [
            'mode' => 'connect',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith(
            'http://localhost:3000/login/oauth/authorize',
            (string) $response->json('auth_url')
        );
    }

    public function test_login_callback_does_not_auto_link_existing_email_by_default(): void
    {
        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
            'services.forgejo.auto_link_by_email' => false,
        ]);

        $existing = $this->createUser('existing@example.com');

        Cache::put('forgejo_oauth_state:test-state', [
            'mode' => 'login',
            'user_id' => null,
            'created_at' => now()->toISOString(),
            'binding' => 'test-binding',
        ], now()->addMinutes(10));

        Http::fake([
            'https://forgejo.example.test/login/oauth/access_token' => Http::response([
                'access_token' => 'forgejo-token',
            ], 200),
            'https://forgejo.example.test/api/v1/user' => Http::response(
                [
                    'id' => 12345,
                    'login' => 'forgejo-user',
                    'email' => 'existing@example.com',
                    'full_name' => 'Forgejo Existing',
                ],
                200,
                [
                    'X-OAuth-Scopes' => 'read:user write:repo',
                    'X-Accepted-OAuth-Scopes' => 'read:user write:repo',
                ]
            ),
        ]);

        $response = $this
            ->withHeader('X-Forgejo-OAuth-Binding', 'test-binding')
            ->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Local account already exists for this email. Sign in and connect Forgejo manually.',
            ]);

        $this->assertNull($existing->fresh()->forgejo_user_id);
    }

    public function test_connect_existing_repo_accepts_https_repository_url(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.git_base_url' => 'https://forgejo.example.test',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('forgejo-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Demo',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/demo',
            'is_public' => false,
        ]);

        Http::fake([
            'https://forgejo.example.test/api/v1/repos/team/repo' => Http::response([
                'id' => 42,
                'full_name' => 'team/repo',
                'clone_url' => 'https://forgejo.example.test/team/repo.git',
                'html_url' => 'https://forgejo.example.test/team/repo',
                'default_branch' => 'main',
                'permissions' => ['push' => true],
            ], 200),
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('setRemote')->once();
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/connect', [
            'mode' => 'existing',
            'repo_url' => 'https://forgejo.example.test/team/repo.git',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('forgejo_repo_full_name', 'team/repo')
            ->assertJsonPath('forgejo_repo_clone_url', 'https://forgejo.example.test/team/repo.git');
    }

    public function test_connect_existing_repo_uses_configured_git_base_url_for_clone_remote(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.git_base_url' => 'http://forgejo.internal:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('forgejo-git-base@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Demo git base',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/demo-git-base',
            'is_public' => false,
        ]);

        Http::fake([
            'https://forgejo.example.test/api/v1/repos/team/repo' => Http::response([
                'id' => 88,
                'full_name' => 'team/repo',
                'clone_url' => 'https://forgejo.example.test/team/repo.git',
                'html_url' => 'https://forgejo.example.test/team/repo',
                'default_branch' => 'main',
                'permissions' => ['push' => true],
            ], 200),
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('setRemote')->once();
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/connect', [
            'mode' => 'existing',
            'repo_url' => 'https://forgejo.example.test/team/repo.git',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('forgejo_repo_full_name', 'team/repo')
            ->assertJsonPath('forgejo_repo_clone_url', 'http://forgejo.internal:3000/team/repo.git');
    }

    public function test_connect_existing_repo_accepts_ssh_repository_url(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.git_base_url' => 'https://forgejo.example.test',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('forgejo-ssh-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Demo SSH',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/demo-ssh',
            'is_public' => false,
        ]);

        Http::fake([
            'https://forgejo.example.test/api/v1/repos/team/repo' => Http::response([
                'id' => 77,
                'full_name' => 'team/repo',
                'clone_url' => 'https://forgejo.example.test/team/repo.git',
                'html_url' => 'https://forgejo.example.test/team/repo',
                'default_branch' => 'main',
                'permissions' => ['push' => true],
            ], 200),
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('setRemote')->once();
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/connect', [
            'mode' => 'existing',
            'repo_url' => 'git@forgejo.example.test:team/repo.git',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('forgejo_repo_full_name', 'team/repo')
            ->assertJsonPath('forgejo_repo_clone_url', 'https://forgejo.example.test/team/repo.git');
    }

    public function test_connect_existing_repo_rejects_foreign_repository_host(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('forgejo-foreign-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Demo Foreign',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/demo-foreign',
            'is_public' => false,
        ]);

        Http::fake();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->never();
            $mock->shouldReceive('setRemote')->never();
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/connect', [
            'mode' => 'existing',
            'repo_url' => 'https://github.com/team/repo.git',
        ]);

        $response
            ->assertStatus(502)
            ->assertJson([
                'message' => 'Repository URL host does not match configured Forgejo host.',
            ]);

        Http::assertNothingSent();
    }

    public function test_save_pushes_pending_commits_even_when_worktree_has_no_changes(): void
    {
        config([
            'services.forgejo.base_url' => 'http://forgejo:3000',
            'services.forgejo.public_url' => 'http://localhost:3000',
            'services.forgejo.git_base_url' => 'http://forgejo:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('save-push-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Save Push',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/save-push',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'gigabyte/my-repo1';
        $project->forgejo_repo_clone_url = 'http://forgejo:3000/gigabyte/my-repo1.git';
        $project->forgejo_default_branch = 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('currentBranch')->once()->andReturn('feature/demo');
            $mock->shouldReceive('commitAll')->once()->andReturn(false);
            $mock->shouldReceive('push')
                ->once()
                ->withArgs(function (
                    string $path,
                    string $remoteUrl,
                    string $branch,
                    string $token,
                    array $author
                ): bool {
                    return $remoteUrl === 'http://forgejo:3000/gigabyte/my-repo1.git'
                        && $branch === 'feature/demo';
                })
                ->andReturn("To http://forgejo:3000/gigabyte/my-repo1.git\n * [new branch]      feature/demo -> feature/demo");
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/save', [
            'message' => 'retry push',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'pushed')
            ->assertJsonPath('branch', 'feature/demo');
        $this->assertNotNull($project->fresh()->forgejo_last_push_at);
    }

    public function test_save_returns_nothing_to_commit_when_no_changes_and_no_commits_exist(): void
    {
        config([
            'services.forgejo.base_url' => 'http://forgejo:3000',
            'services.forgejo.public_url' => 'http://localhost:3000',
            'services.forgejo.git_base_url' => 'http://forgejo:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('save-empty-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Save Empty',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/save-empty',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'gigabyte/my-repo1';
        $project->forgejo_repo_clone_url = 'http://forgejo:3000/gigabyte/my-repo1.git';
        $project->forgejo_default_branch = 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('currentBranch')->once()->andReturn('main');
            $mock->shouldReceive('commitAll')->once()->andReturn(false);
            $mock->shouldReceive('push')->once()->andThrow(
                new \RuntimeException("error: src refspec main does not match any\nerror: failed to push some refs")
            );
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/save', [
            'message' => 'retry push',
        ]);

        $response->assertOk()->assertJsonPath('status', 'nothing_to_commit');
        $this->assertNull($project->fresh()->forgejo_last_push_at);
    }

    public function test_save_retries_push_with_alternative_forgejo_url_when_primary_is_unreachable(): void
    {
        config([
            'services.forgejo.base_url' => 'http://forgejo:3000',
            'services.forgejo.public_url' => 'http://localhost:3000',
            'services.forgejo.git_base_url' => 'http://localhost:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('save-retry-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Save Retry',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/save-retry',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'gigabyte/my-repo1';
        $project->forgejo_repo_clone_url = 'http://localhost:3000/gigabyte/my-repo1.git';
        $project->forgejo_default_branch = 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('currentBranch')->once()->andReturn('feature/retry');
            $mock->shouldReceive('commitAll')->once()->andReturn(false);
            $mock->shouldReceive('push')
                ->once()
                ->ordered()
                ->withArgs(function (
                    string $path,
                    string $remoteUrl,
                    string $branch,
                    string $token,
                    array $author
                ): bool {
                    return $remoteUrl === 'http://localhost:3000/gigabyte/my-repo1.git'
                        && $branch === 'feature/retry';
                })
                ->andThrow(new \RuntimeException(
                    "fatal: unable to access 'http://localhost:3000/gigabyte/my-repo1.git/': Failed to connect to localhost port 3000 after 0 ms: Couldn't connect to server"
                ));

            $mock->shouldReceive('push')
                ->once()
                ->ordered()
                ->withArgs(function (
                    string $path,
                    string $remoteUrl,
                    string $branch,
                    string $token,
                    array $author
                ): bool {
                    return $remoteUrl === 'http://forgejo:3000/gigabyte/my-repo1.git'
                        && $branch === 'feature/retry';
                })
                ->andReturn("To http://forgejo:3000/gigabyte/my-repo1.git\n * [new branch]      feature/retry -> feature/retry");
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/save', [
            'message' => 'retry push',
        ]);

        $response->assertOk()->assertJsonPath('status', 'pushed');
        $this->assertSame(
            'http://forgejo:3000/gigabyte/my-repo1.git',
            (string) $project->fresh()->forgejo_repo_clone_url
        );
    }

    public function test_save_rejects_push_from_detached_head_workspace(): void
    {
        config([
            'services.forgejo.base_url' => 'http://forgejo:3000',
            'services.forgejo.public_url' => 'http://localhost:3000',
            'services.forgejo.git_base_url' => 'http://forgejo:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('save-detached-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'Save Detached',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/save-detached',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'gigabyte/my-repo1';
        $project->forgejo_repo_clone_url = 'http://forgejo:3000/gigabyte/my-repo1.git';
        $project->forgejo_default_branch = 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('currentBranch')->once()->andReturnNull();
            $mock->shouldNotReceive('commitAll');
            $mock->shouldNotReceive('push');
        });

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/save', [
            'message' => 'detached push',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Current workspace is on a detached commit. Create or switch to a branch before pushing to Forgejo.'
            );
    }

    public function test_collaborator_can_create_pull_request_for_configured_repository(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
            'services.forgejo.public_url' => 'https://forgejo.example.test',
            'services.forgejo.git_base_url' => 'https://forgejo.example.test',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('pr-owner@example.com');
        $owner->forgejo_access_token = 'owner-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $collaborator = $this->createUser('pr-collab@example.com');
        $collaborator->forgejo_access_token = 'collab-token';
        $collaborator->forgejo_connected_at = now();
        $collaborator->save();

        $project = Project::query()->create([
            'name' => 'PR Demo',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/pr-demo',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'team/repo';
        $project->forgejo_repo_clone_url = 'https://forgejo.example.test/team/repo.git';
        $project->forgejo_default_branch = 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $collaborator->user_id,
        ]);

        Http::fake([
            'https://forgejo.example.test/api/v1/repos/team/repo/pulls' => Http::response([
                'number' => 17,
                'title' => 'Improve docs',
                'html_url' => 'https://forgejo.example.test/team/repo/pulls/17',
            ], 201),
        ]);

        $this->mock(ProjectGitService::class, function (MockInterface $mock) use ($collaborator): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('commitAll')->once()->andReturn(true);
            $mock->shouldReceive('pushRefspec')
                ->once()
                ->withArgs(function (
                    string $path,
                    string $remoteUrl,
                    string $refspec,
                    string $token,
                    array $author,
                    bool $setUpstream
                ) use ($collaborator): bool {
                    return $remoteUrl === 'https://forgejo.example.test/team/repo.git'
                        && str_starts_with($refspec, 'HEAD:refs/heads/livecode/u'.$collaborator->user_id.'/pr-')
                        && $token === 'collab-token'
                        && $setUpstream === false;
                })
                ->andReturn("To https://forgejo.example.test/team/repo.git\n * [new branch]      HEAD -> livecode branch");
        });

        $token = $collaborator->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/pull-request', [
            'title' => 'Improve docs',
            'body' => 'Adds docs updates.',
            'message' => 'docs: update readme',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('status', 'pull_request_created')
            ->assertJsonPath('number', 17)
            ->assertJsonPath('html_url', 'https://forgejo.example.test/team/repo/pulls/17');

        $this->assertNotNull($project->fresh()->forgejo_last_push_at);
    }

    public function test_pull_request_returns_nothing_to_commit_when_repository_has_no_commits(): void
    {
        config([
            'services.forgejo.base_url' => 'http://forgejo:3000',
            'services.forgejo.public_url' => 'http://localhost:3000',
            'services.forgejo.git_base_url' => 'http://forgejo:3000',
            'services.forgejo.client_id' => 'client-id',
            'services.forgejo.client_secret' => 'client-secret',
            'services.forgejo.redirect_url' => 'https://app.example.test/forgejo/callback',
        ]);

        $owner = $this->createUser('pr-empty-owner@example.com');
        $owner->forgejo_access_token = 'forgejo-token';
        $owner->forgejo_connected_at = now();
        $owner->save();

        $project = Project::query()->create([
            'name' => 'PR Empty',
            'description' => 'demo',
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/pr-empty',
            'is_public' => false,
        ]);
        $project->git_enabled = true;
        $project->forgejo_repo_full_name = 'gigabyte/my-repo1';
        $project->forgejo_repo_clone_url = 'http://forgejo:3000/gigabyte/my-repo1.git';
        $project->forgejo_default_branch = 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        $this->mock(ProjectGitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initRepository')->once();
            $mock->shouldReceive('commitAll')->once()->andReturn(false);
            $mock->shouldReceive('pushRefspec')->once()->andThrow(
                new \RuntimeException("error: src refspec HEAD does not match any\nerror: failed to push some refs")
            );
        });

        Http::fake();

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/forgejo/pull-request', [
            'title' => 'PR without commits',
        ]);

        $response->assertOk()->assertJsonPath('status', 'nothing_to_commit');
        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_update_foreign_project(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');

        $project = Project::query()->create([
            'name' => 'Secret Project',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/test-owner/secret',
            'is_public' => false,
        ]);

        $token = $intruder->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/projects/'.$project->project_id, [
            'name' => 'Hacked name',
        ]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Access denied.',
            ]);
    }

    public function test_owner_project_delete_removes_project_directory(): void
    {
        Storage::fake('local');

        $owner = $this->createUser('owner@example.com');
        $path = 'projects/test-owner/demo';

        Storage::disk('local')->makeDirectory($path);
        Storage::disk('local')->put($path.'/index.php', '<?php echo "ok";');

        $project = Project::query()->create([
            'name' => 'Demo',
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => $path,
            'is_public' => false,
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->deleteJson('/api/projects/'.$project->project_id);

        $response->assertNoContent();
        Storage::disk('local')->assertMissing($path.'/index.php');
        $this->assertDatabaseMissing('projects', ['project_id' => $project->project_id]);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test user',
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'language' => 'rus',
        ]);
    }
}

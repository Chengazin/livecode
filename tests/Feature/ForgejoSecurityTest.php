<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ForgejoSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_callback_requires_authenticated_user(): void
    {
        Cache::put('forgejo_oauth_state:test-state', [
            'mode' => 'connect',
            'user_id' => 999,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(10));

        $response = $this->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthorized.',
            ]);
    }

    public function test_connect_callback_rejects_state_user_mismatch(): void
    {
        $stateOwner = $this->createUser('state-owner@example.com');
        $anotherUser = $this->createUser('other@example.com');

        Cache::put('forgejo_oauth_state:test-state', [
            'mode' => 'connect',
            'user_id' => $stateOwner->user_id,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(10));

        $token = $anotherUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'State user mismatch.',
            ]);
    }

    public function test_start_connect_allows_sanctum_authenticated_user(): void
    {
        config([
            'services.forgejo.base_url' => 'https://forgejo.example.test',
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

    public function test_login_callback_does_not_auto_link_existing_email_by_default(): void
    {
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

        $response = $this->getJson('/api/forgejo/oauth/callback?code=fake&state=test-state');

        $response
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Local account already exists for this email. Sign in and connect Forgejo manually.',
            ]);

        $this->assertNull($existing->fresh()->forgejo_user_id);
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

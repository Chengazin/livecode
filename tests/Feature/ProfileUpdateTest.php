<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_email_and_password_without_current_password(): void
    {
        $user = $this->createUser('profile-update-old@example.com', 'password123');
        $token = $user->createToken('profile-update')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/me/profile', [
            'name' => 'Updated Nickname',
            'email' => 'profile-update-new@example.com',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', 'Updated Nickname')
            ->assertJsonPath('email', 'profile-update-new@example.com')
            ->assertJsonPath('logged_out_all', true);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertSame('profile-update-new@example.com', $freshUser->email);
        $this->assertTrue(Hash::check('new-password-123', (string) $freshUser->password_hash));
        $this->assertSame(0, $freshUser->tokens()->count());
    }

    public function test_profile_update_allows_email_change_without_current_password(): void
    {
        $user = $this->createUser('profile-email-old@example.com', 'password123');
        $token = $user->createToken('profile-update')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/me/profile', [
            'email' => 'profile-email-new@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('email', 'profile-email-new@example.com')
            ->assertJsonPath('logged_out_all', true);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertSame('profile-email-new@example.com', $freshUser->email);
        $this->assertSame(0, $freshUser->tokens()->count());
    }

    public function test_profile_update_keeps_sessions_for_non_sensitive_changes(): void
    {
        $user = $this->createUser('profile-regular-old@example.com', 'password123');
        $token = $user->createToken('profile-update')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/me/profile', [
            'name' => 'Regular update',
            'language' => 'eng',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', 'Regular update')
            ->assertJsonPath('language', 'eng')
            ->assertJsonPath('logged_out_all', false);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertSame('Regular update', $freshUser->name);
        $this->assertSame('eng', $freshUser->language);
        $this->assertSame(1, $freshUser->tokens()->count());
    }

    public function test_profile_update_rejects_password_confirmation_mismatch(): void
    {
        $user = $this->createUser('profile-sensitive-old@example.com', 'password123');
        $token = $user->createToken('profile-update')->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/me/profile', [
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'another-password',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertSame('profile-sensitive-old@example.com', $freshUser->email);
        $this->assertTrue(Hash::check('password123', (string) $freshUser->password_hash));
    }

    private function createUser(string $email, string $password): User
    {
        return User::query()->create([
            'name' => 'Profile User',
            'email' => $email,
            'password_hash' => Hash::make($password),
            'status' => 'active',
            'language' => 'rus',
        ]);
    }
}

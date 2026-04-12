<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHashingSecurityTest extends TestCase
{
    /**
     * Verify that passwords are hashed with bcrypt and salted
     */
    public function test_passwords_are_hashed_with_bcrypt_and_salt()
    {
        // Create a user with plain password
        $plainPassword = 'secure_password_12345';

        $user = User::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => Hash::make($plainPassword),
            'status' => 'active',
        ]);

        // Verify that password_hash is not the plain password
        $this->assertNotEquals($plainPassword, $user->password_hash);

        // Verify that password_hash starts with bcrypt prefix ($2y$)
        $this->assertStringStartsWith('$2y$', $user->password_hash);

        // Verify that the hashed password can be verified against plain password
        $this->assertTrue(Hash::check($plainPassword, $user->password_hash));

        // Clean up
        $user->forceDelete();
    }

    /**
     * Verify that same password produces different hashes (due to different salts)
     */
    public function test_same_password_produces_different_hashes_due_to_unique_salts()
    {
        $plainPassword = 'same_password_for_testing';

        // Create two hashes of the same password
        $hash1 = Hash::make($plainPassword);
        $hash2 = Hash::make($plainPassword);

        // Verify that hashes are different (because each hash has a unique salt)
        $this->assertNotEquals($hash1, $hash2);

        // Verify that both hashes verify against the plain password
        $this->assertTrue(Hash::check($plainPassword, $hash1));
        $this->assertTrue(Hash::check($plainPassword, $hash2));
    }

    /**
     * Verify bcrypt rounds configuration
     */
    public function test_bcrypt_rounds_configuration()
    {
        $config = config('hashing.bcrypt.rounds');

        // Verify that bcrypt rounds is configured (minimum 4 for tests, 10+ for production)
        $this->assertGreaterThanOrEqual(4, $config);

        // If not in test environment, verify stronger security
        if (config('app.env') !== 'testing') {
            $this->assertGreaterThanOrEqual(12, $config);
        }
    }

    /**
     * Verify that bcrypt driver is default
     */
    public function test_bcrypt_is_default_hash_driver()
    {
        $driver = config('hashing.driver');
        $this->assertEquals('bcrypt', $driver);
    }

    /**
     * Test registration with password hashing
     */
    public function test_registration_hashes_password_with_salt()
    {
        $payload = [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'SecurePassword123!',
            'language' => 'rus',
            'device_name' => 'test-device',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertCreated();

        $user = User::query()->where('email', $payload['email'])->first();

        // Verify user was created with hashed password
        $this->assertNotNull($user);
        $this->assertNotEquals($payload['password'], $user->password_hash);

        // Verify password starts with bcrypt prefix
        $this->assertStringStartsWith('$2y$', $user->password_hash);

        // Verify the password can be verified
        $this->assertTrue(Hash::check($payload['password'], $user->password_hash));

        // Clean up
        $user->forceDelete();
    }
}

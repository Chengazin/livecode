<?php

namespace Tests\Feature;

use App\Models\RegistrationVerification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();

        $payload = [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'SecurePassword123!',
            'language' => 'rus',
        ];

        $initiateResponse = $this->postJson('/api/auth/register/initiate', $payload);

        $initiateResponse->assertStatus(202)->assertJsonStructure([
            'registration_verification_id',
            'email',
        ]);

        $verificationId = (int) $initiateResponse->json('registration_verification_id');
        $verification = RegistrationVerification::query()->find($verificationId);

        $this->assertNotNull($verification);
        $this->assertNotEquals($payload['password'], $verification->password_hash);
        $this->assertStringStartsWith('$2y$', $verification->password_hash);
        $this->assertTrue(Hash::check($payload['password'], $verification->password_hash));

        $verifyResponse = $this->postJson('/api/auth/register/verify', [
            'registration_verification_id' => $verificationId,
            'verification_code' => $verification->verification_code,
            'device_name' => 'test-device',
        ]);

        $verifyResponse->assertCreated();

        $user = User::query()->where('email', $payload['email'])->firstOrFail();

        // Verify user was created with hashed password
        $this->assertNotEquals($payload['password'], $user->password_hash);

        // Verify password starts with bcrypt prefix
        $this->assertStringStartsWith('$2y$', $user->password_hash);

        // Verify the password can be verified
        $this->assertTrue(Hash::check($payload['password'], $user->password_hash));

        // Clean up
        $user->forceDelete();
    }
}

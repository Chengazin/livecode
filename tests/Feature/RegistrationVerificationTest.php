<?php

namespace Tests\Feature;

use App\Models\RegistrationVerification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * Test that registration initiate creates verification record and sends email
     */
    public function test_register_initiate_creates_verification_record()
    {
        $payload = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePassword123!',
            'language' => 'rus',
        ];

        $response = $this->postJson('/api/auth/register/initiate', $payload);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'message',
            'registration_verification_id',
            'email',
            'expires_in_minutes',
        ]);

        // Check that verification record was created
        $this->assertDatabaseHas('registration_verifications', [
            'email' => $payload['email'],
            'name' => $payload['name'],
            'language' => $payload['language'],
        ]);

        // Check that password is hashed and stored
        $verification = RegistrationVerification::query()
            ->where('email', $payload['email'])
            ->first();
        
        $this->assertNotEquals($payload['password'], $verification->password_hash);
        $this->assertStringStartsWith('$2y$', $verification->password_hash);
    }

    /**
     * Test that duplicate email in initiate is rejected
     */
    public function test_register_initiate_rejects_duplicate_email()
    {
        // Create existing user
        User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password_hash' => bcrypt('password'),
            'status' => 'active',
        ]);

        $payload = [
            'name' => 'New User',
            'email' => 'existing@example.com',
            'password' => 'NewPassword123!',
        ];

        $response = $this->postJson('/api/auth/register/initiate', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * Test that short password is rejected
     */
    public function test_register_initiate_rejects_short_password()
    {
        $payload = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'short',  // Less than 8 characters
        ];

        $response = $this->postJson('/api/auth/register/initiate', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    /**
     * Test successful registration verification with correct code
     */
    public function test_register_verify_with_correct_code()
    {
        // Create verification record
        $verification = RegistrationVerification::createForRegistration(
            email: 'newuser@example.com',
            name: 'New User',
            passwordHash: bcrypt('SecurePassword123!'),
            language: 'rus'
        );

        $payload = [
            'registration_verification_id' => $verification->registration_verification_id,
            'verification_code' => $verification->verification_code,
            'device_name' => 'Test Device',
        ];

        $response = $this->postJson('/api/auth/register/verify', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'token_type',
            'access_token',
            'user' => [
                'user_id',
                'name',
                'email',
                'language',
                'status',
                'is_admin',
            ],
        ]);

        // Check that user was created
        $user = User::query()->where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('New User', $user->name);
        $this->assertEquals('active', $user->status);
        $this->assertEquals('rus', $user->language);

        // Check that verification record was deleted
        $this->assertDatabaseMissing('registration_verifications', [
            'registration_verification_id' => $verification->registration_verification_id,
        ]);
    }

    /**
     * Test that incorrect code is rejected and attempts are tracked
     */
    public function test_register_verify_with_incorrect_code()
    {
        $verification = RegistrationVerification::createForRegistration(
            email: 'newuser@example.com',
            name: 'New User',
            passwordHash: bcrypt('SecurePassword123!'),
            language: 'rus'
        );

        // First incorrect attempt
        $payload = [
            'registration_verification_id' => $verification->registration_verification_id,
            'verification_code' => '000000',  // Wrong code
        ];

        $response = $this->postJson('/api/auth/register/verify', $payload);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Invalid verification code.']);

        // Check that attempts was incremented
        $verification->refresh();
        $this->assertEquals(1, $verification->attempts);
        $this->assertNull($verification->verified_at);

        // User should not be created yet
        $this->assertDatabaseMissing('users', ['email' => 'newuser@example.com']);
    }

    /**
     * Test that max attempts are enforced
     */
    public function test_register_verify_max_attempts_exceeded()
    {
        $verification = RegistrationVerification::createForRegistration(
            email: 'newuser@example.com',
            name: 'New User',
            passwordHash: bcrypt('SecurePassword123!'),
            language: 'rus'
        );

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/auth/register/verify', [
                'registration_verification_id' => $verification->registration_verification_id,
                'verification_code' => 'wrong' . $i,
            ]);

            if ($i < 2) {
                $response->assertStatus(422);
            } else {
                // On 3rd attempt, should get 429
                $response->assertStatus(429);
                $response->assertJson(['message' => 'Too many failed attempts. Please register again.']);
            }
        }

        // Verification record should be deleted
        $this->assertDatabaseMissing('registration_verifications', [
            'registration_verification_id' => $verification->registration_verification_id,
        ]);
    }

    /**
     * Test that expired codes are rejected
     */
    public function test_register_verify_with_expired_code()
    {
        $verification = RegistrationVerification::query()->create([
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'password_hash' => bcrypt('SecurePassword123!'),
            'language' => 'rus',
            'verification_code' => RegistrationVerification::generateCode(),
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => now()->subMinutes(5),  // Expired 5 minutes ago
        ]);

        $payload = [
            'registration_verification_id' => $verification->registration_verification_id,
            'verification_code' => $verification->verification_code,
        ];

        $response = $this->postJson('/api/auth/register/verify', $payload);

        $response->assertStatus(422);
    }

    /**
     * Test resend verification code
     */
    public function test_register_resend_code()
    {
        $verification = RegistrationVerification::createForRegistration(
            email: 'newuser@example.com',
            name: 'New User',
            passwordHash: bcrypt('SecurePassword123!'),
            language: 'rus'
        );

        $oldCode = $verification->verification_code;

        $response = $this->postJson('/api/auth/register/resend-code', [
            'registration_verification_id' => $verification->registration_verification_id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Verification code has been resent to your email',
            'expires_in_minutes' => 30,
        ]);

        // Check that new code was generated
        $verification->refresh();
        $this->assertNotEquals($oldCode, $verification->verification_code);
        $this->assertEquals(0, $verification->attempts);  // Attempts should be reset
    }

    /**
     * Test that resend code fails for expired verification
     */
    public function test_register_resend_code_for_expired_verification()
    {
        $verification = RegistrationVerification::query()->create([
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'password_hash' => bcrypt('SecurePassword123!'),
            'language' => 'rus',
            'verification_code' => RegistrationVerification::generateCode(),
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => now()->subMinutes(5),  // Expired
        ]);

        $response = $this->postJson('/api/auth/register/resend-code', [
            'registration_verification_id' => $verification->registration_verification_id,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Verification code has expired. Please register again.']);

        // Verification record should be deleted
        $this->assertDatabaseMissing('registration_verifications', [
            'registration_verification_id' => $verification->registration_verification_id,
        ]);
    }

    /**
     * Test that previous verification is deleted when new one is initiated
     */
    public function test_register_initiate_deletes_previous_verification()
    {
        // Create first verification
        $first = RegistrationVerification::createForRegistration(
            email: 'test@example.com',
            name: 'User',
            passwordHash: bcrypt('password1'),
        );

        // Initiate registration again with same email
        $this->postJson('/api/auth/register/initiate', [
            'name' => 'Updated Name',
            'email' => 'test@example.com',
            'password' => 'password2',
        ]);

        // First verification should be deleted
        $this->assertDatabaseMissing('registration_verifications', [
            'registration_verification_id' => $first->registration_verification_id,
        ]);

        // New verification should exist with new code
        $new = RegistrationVerification::query()->where('email', 'test@example.com')->first();
        $this->assertNotNull($new);
        $this->assertNotEquals($first->verification_code, $new->verification_code);
    }
}

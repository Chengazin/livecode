<?php

namespace App\Services\Auth;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\RegistrationVerification;
use App\Models\User;
use App\Services\CaptchaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthWorkflowService
{
    /**
     * @param array<string, mixed> $data
     */
    public function registerInitiate(array $data, ?string $ip): AuthResult
    {
        try {
            if (config('captcha.verify_on.registration_initiate') && CaptchaService::isEnabled()) {
                if (! CaptchaService::verify((string) ($data['captcha_token'] ?? ''), $ip)) {
                    Log::warning('Registration initiate: captcha verification failed', [
                        'email' => (string) ($data['email'] ?? ''),
                        'ip' => $ip,
                    ]);

                    return new AuthResult(422, [
                        'message' => 'Captcha verification failed. Please try again.',
                    ]);
                }
            }

            RegistrationVerification::query()->where('email', (string) $data['email'])->delete();

            $verification = RegistrationVerification::createForRegistration(
                email: (string) $data['email'],
                name: (string) $data['name'],
                passwordHash: Hash::make((string) $data['password']),
                language: (string) ($data['language'] ?? 'rus'),
                expirationMinutes: 30
            );

            Mail::to((string) $data['email'])
                ->queue(new RegistrationVerificationCodeMail($verification));

            Log::info('Registration initiated - verification code sent', [
                'email' => (string) $data['email'],
                'verification_id' => $verification->registration_verification_id,
            ]);

            return new AuthResult(202, [
                'message' => 'Verification code has been sent to your email',
                'registration_verification_id' => $verification->registration_verification_id,
                'email' => (string) $data['email'],
                'expires_in_minutes' => 30,
            ]);
        } catch (\Exception $e) {
            Log::error('Registration initiation failed', [
                'email' => (string) ($data['email'] ?? 'unknown'),
                'error' => $e->getMessage(),
            ]);

            return new AuthResult(500, [
                'message' => 'Failed to initiate registration. Please try again.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function registerVerify(array $data): AuthResult
    {
        try {
            $verification = RegistrationVerification::query()
                ->findOrFail((int) $data['registration_verification_id']);

            if (! $verification->verify((string) $data['verification_code'])) {
                Log::warning('Invalid verification code attempted', [
                    'email' => $verification->email,
                    'attempts' => $verification->attempts,
                ]);

                if ($verification->attempts >= $verification->max_attempts) {
                    $verification->delete();

                    return new AuthResult(429, [
                        'message' => 'Too many failed attempts. Please register again.',
                    ]);
                }

                return new AuthResult(422, [
                    'message' => 'Invalid verification code.',
                    'attempts_remaining' => $verification->max_attempts - $verification->attempts,
                ]);
            }

            $user = User::query()->create([
                'name' => $verification->name,
                'email' => $verification->email,
                'password_hash' => $verification->password_hash,
                'status' => 'active',
                'language' => $verification->language,
            ]);

            $verification->delete();

            $tokenName = (string) ($data['device_name'] ?? 'api');
            $plainTextToken = $user->createToken($tokenName)->plainTextToken;

            Log::info('User registered successfully', [
                'user_id' => $user->user_id,
                'email' => $user->email,
            ]);

            return new AuthResult(201, [
                'token_type' => 'Bearer',
                'access_token' => $plainTextToken,
                'user' => $this->serializeUser($user),
            ]);
        } catch (ModelNotFoundException $e) {
            Log::warning('Registration verification failed - invalid ID', [
                'verification_id' => (string) ($data['registration_verification_id'] ?? 'unknown'),
            ]);

            return new AuthResult(404, [
                'message' => 'Invalid verification session. Please register again.',
            ]);
        } catch (\Exception $e) {
            Log::error('Registration verification failed', [
                'error' => $e->getMessage(),
            ]);

            return new AuthResult(500, [
                'message' => 'Registration failed. Please try again.',
            ]);
        }
    }

    public function registerResendCode(int $verificationId): AuthResult
    {
        try {
            $verification = RegistrationVerification::query()->findOrFail($verificationId);

            if ($verification->verified_at !== null) {
                return new AuthResult(400, [
                    'message' => 'This email has already been verified.',
                ]);
            }

            if ($verification->isExpired()) {
                $verification->delete();

                return new AuthResult(400, [
                    'message' => 'Verification code has expired. Please register again.',
                ]);
            }

            $verification->update([
                'verification_code' => RegistrationVerification::generateCode(),
                'attempts' => 0,
            ]);

            Mail::to($verification->email)
                ->queue(new RegistrationVerificationCodeMail($verification));

            Log::info('Verification code resent', [
                'email' => $verification->email,
                'verification_id' => $verification->registration_verification_id,
            ]);

            return new AuthResult(200, [
                'message' => 'Verification code has been resent to your email',
                'expires_in_minutes' => 30,
            ]);
        } catch (ModelNotFoundException $e) {
            return new AuthResult(404, [
                'message' => 'Invalid verification session.',
            ]);
        } catch (\Exception $e) {
            Log::error('Resend verification code failed', [
                'error' => $e->getMessage(),
            ]);

            return new AuthResult(500, [
                'message' => 'Failed to resend verification code. Please try again.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function login(array $data): AuthResult
    {
        $user = User::query()->where('email', (string) $data['email'])->first();

        if (
            ! $user
            || ! Hash::check((string) $data['password'], (string) $user->password_hash)
            || $user->status !== 'active'
        ) {
            Log::info('Login attempt failed', [
                'email' => (string) ($data['email'] ?? 'unknown'),
            ]);

            return new AuthResult(401, ['message' => 'Invalid credentials.']);
        }

        $tokenName = (string) ($data['device_name'] ?? 'api');
        $plainTextToken = $user->createToken($tokenName)->plainTextToken;

        Log::info('User logged in successfully', [
            'user_id' => $user->user_id,
            'email' => $user->email,
        ]);

        return new AuthResult(200, [
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function captchaConfig(): array
    {
        return [
            'enabled' => CaptchaService::isEnabled(),
            'provider' => config('captcha.provider'),
            'site_key' => CaptchaService::getSiteKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $payload = $user->toArray();
        $payload['is_admin'] = $user->admin()->exists();

        return $payload;
    }
}

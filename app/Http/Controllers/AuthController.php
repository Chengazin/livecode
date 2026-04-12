<?php
namespace App\Http\Controllers;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\RegistrationVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /**
     * Step 1: Initiate user registration with email verification.
     *
     * Passwords are hashed using bcrypt with a configurable number of rounds.
     * Each password hash is automatically salted during the hashing process.
     *
     * Security features:
     * - Bcrypt hashing with automatic salt generation
     * - Email verification code sent to provided email
     * - Configurable code expiration (default: 30 minutes)
     * - Rate limiting on registration endpoint
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function registerInitiate(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'language' => ['sometimes', 'string', 'in:rus,eng'],
        ]);

        try {
            // Delete any existing verification records for this email
            RegistrationVerification::query()->where('email', $data['email'])->delete();

            // Create verification record
            $verification = RegistrationVerification::createForRegistration(
                email: $data['email'],
                name: $data['name'],
                passwordHash: Hash::make($data['password']),
                language: $data['language'] ?? 'rus',
                expirationMinutes: 30
            );

            // Send verification code via email
            Mail::to($data['email'])
                ->queue(new RegistrationVerificationCodeMail($verification));

            Log::info('Registration initiated - verification code sent', [
                'email' => $data['email'],
                'verification_id' => $verification->registration_verification_id,
            ]);

            return response()->json([
                'message' => 'Verification code has been sent to your email',
                'registration_verification_id' => $verification->registration_verification_id,
                'email' => $data['email'],
                'expires_in_minutes' => 30,
            ], 202);
        } catch (\Exception $e) {
            Log::error('Registration initiation failed', [
                'email' => $data['email'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to initiate registration. Please try again.',
            ], 500);
        }
    }

    /**
     * Step 2: Verify email with code and complete registration.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function registerVerify(Request $request)
    {
        $data = $request->validate([
            'registration_verification_id' => ['required', 'integer'],
            'verification_code' => ['required', 'string', 'size:6'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            $verification = RegistrationVerification::query()
                ->findOrFail($data['registration_verification_id']);

            // Check if verification is valid and code matches
            if (!$verification->verify($data['verification_code'])) {
                Log::warning('Invalid verification code attempted', [
                    'email' => $verification->email,
                    'attempts' => $verification->attempts,
                ]);

                if ($verification->attempts >= $verification->max_attempts) {
                    $verification->delete();
                    return response()->json([
                        'message' => 'Too many failed attempts. Please register again.',
                    ], 429);
                }

                return response()->json([
                    'message' => 'Invalid verification code.',
                    'attempts_remaining' => $verification->max_attempts - $verification->attempts,
                ], 422);
            }

            // Create the user account
            $user = User::query()->create([
                'name' => $verification->name,
                'email' => $verification->email,
                'password_hash' => $verification->password_hash,
                'status' => 'active',
                'language' => $verification->language,
            ]);

            // Delete verification record after successful registration
            $verification->delete();

            // Create API token
            $tokenName = $data['device_name'] ?? 'api';
            $plainTextToken = $user->createToken($tokenName)->plainTextToken;

            Log::info('User registered successfully', [
                'user_id' => $user->user_id,
                'email' => $user->email,
            ]);

            return response()->json([
                'token_type' => 'Bearer',
                'access_token' => $plainTextToken,
                'user' => $this->serializeUser($user),
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Registration verification failed - invalid ID', [
                'verification_id' => $data['registration_verification_id'] ?? 'unknown',
            ]);

            return response()->json([
                'message' => 'Invalid verification session. Please register again.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Registration verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Resend verification code to email.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function registerResendCode(Request $request)
    {
        $data = $request->validate([
            'registration_verification_id' => ['required', 'integer'],
        ]);

        try {
            $verification = RegistrationVerification::query()
                ->findOrFail($data['registration_verification_id']);

            // Check if already verified
            if ($verification->verified_at !== null) {
                return response()->json([
                    'message' => 'This email has already been verified.',
                ], 400);
            }

            // Check if expired
            if ($verification->isExpired()) {
                $verification->delete();
                return response()->json([
                    'message' => 'Verification code has expired. Please register again.',
                ], 400);
            }

            // Generate new code and reset attempts
            $verification->update([
                'verification_code' => RegistrationVerification::generateCode(),
                'attempts' => 0,
            ]);

            // Send verification code via email
            Mail::to($verification->email)
                ->queue(new RegistrationVerificationCodeMail($verification));

            Log::info('Verification code resent', [
                'email' => $verification->email,
                'verification_id' => $verification->registration_verification_id,
            ]);

            return response()->json([
                'message' => 'Verification code has been resent to your email',
                'expires_in_minutes' => 30,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Invalid verification session.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Resend verification code failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to resend verification code. Please try again.',
            ], 500);
        }
    }

    /**
     * Authenticate user and return API token.
     *
     * Uses Hash::check() to verify the provided password against the stored
     * bcrypt hash. This method safely compares the password without revealing
     * timing information about the comparison.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (
            ! $user
            || ! Hash::check($data['password'], $user->password_hash)
            || $user->status !== 'active'
        ) {
            // Deliberately return the same error for auth failures to reduce account enumeration.
            Log::info('Login attempt failed', [
                'email' => $data['email'] ?? 'unknown',
            ]);
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $tokenName = $data['device_name'] ?? 'api';
        $plainTextToken = $user->createToken($tokenName)->plainTextToken;

        // Log successful login
        Log::info('User logged in successfully', [
            'user_id' => $user->user_id,
            'email' => $user->email,
        ]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->noContent();
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        return response()->noContent();
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



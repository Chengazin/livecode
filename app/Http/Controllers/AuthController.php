<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Register a new user with secure password hashing.
     *
     * Passwords are hashed using bcrypt with a configurable number of rounds.
     * Each password hash is automatically salted during the hashing process.
     * The salt ensures that identical passwords produce different hashes,
     * making rainbow table attacks infeasible.
     *
     * Security features:
     * - Bcrypt hashing with automatic salt generation
     * - Configurable rounds (BCRYPT_ROUNDS env variable, default: 12)
     * - Email uniqueness validation
     * - Rate limiting on registration endpoint
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'language' => ['sometimes', 'string', 'in:rus,eng'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            // Create user with hashed password
            // Hash::make() automatically generates a unique salt for each password
            // and uses bcrypt algorithm as configured in config/hashing.php
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'status' => 'active',
                'language' => $data['language'] ?? 'rus',
            ]);

            // Create API token
            $tokenName = $data['device_name'] ?? 'api';
            $plainTextToken = $user->createToken($tokenName)->plainTextToken;

            // Log successful registration
            Log::info('User registered successfully', [
                'user_id' => $user->user_id,
                'email' => $user->email,
            ]);

            return response()->json([
                'token_type' => 'Bearer',
                'access_token' => $plainTextToken,
                'user' => $this->serializeUser($user),
            ], 201);
        } catch (\Exception $e) {
            // Log registration failure
            Log::warning('User registration failed', [
                'email' => $data['email'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            throw $e;
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


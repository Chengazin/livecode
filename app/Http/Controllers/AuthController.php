<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'language' => ['sometimes', 'string', 'in:rus,eng'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'status' => 'active',
            'language' => $data['language'] ?? 'rus',
        ]);

        $tokenName = $data['device_name'] ?? 'api';
        $plainTextToken = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'user' => $this->serializeUser($user),
        ], 201);
    }

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
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $tokenName = $data['device_name'] ?? 'api';
        $plainTextToken = $user->createToken($tokenName)->plainTextToken;

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

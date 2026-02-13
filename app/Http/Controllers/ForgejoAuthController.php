<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ForgejoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ForgejoAuthController extends Controller
{
    public function start(Request $request, ForgejoService $forgejo)
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:login,connect'],
        ]);

        $user = $request->user();
        if ($data['mode'] === 'connect' && ! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $state = Str::random(40);
        $payload = [
            'mode' => $data['mode'],
            'user_id' => $user?->user_id,
            'created_at' => now()->toISOString(),
        ];

        Cache::put($this->stateKey($state), $payload, now()->addMinutes(10));

        return response()->json([
            'auth_url' => $forgejo->buildAuthorizationUrl($state),
            'state' => $state,
            'mode' => $data['mode'],
        ]);
    }

    public function callback(Request $request, ForgejoService $forgejo)
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $payload = Cache::pull($this->stateKey($data['state']));

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid state.'], 422);
        }

        try {
            $tokenData = $forgejo->exchangeCode($data['code']);
            $accessToken = (string) ($tokenData['access_token'] ?? '');

            if ($accessToken === '') {
                return response()->json(['message' => 'Invalid token response.'], 502);
            }

            $forgejoMeta = $forgejo->fetchUserWithMeta($accessToken);
            $forgejoUser = $forgejoMeta['user'];
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $forgejoUserId = (int) ($forgejoUser['id'] ?? 0);
        $forgejoLogin = (string) ($forgejoUser['login'] ?? '');
        $forgejoEmail = (string) ($forgejoUser['email'] ?? '');
        $forgejoName = (string) ($forgejoUser['full_name'] ?? $forgejoLogin);

        if ($forgejoUserId === 0 || $forgejoLogin === '') {
            return response()->json(['message' => 'Invalid user payload.'], 502);
        }

        $user = null;

        if ($payload['mode'] === 'login') {
            $user = User::query()->where('forgejo_user_id', $forgejoUserId)->first();

            if (! $user && $forgejoEmail !== '') {
                $user = User::query()->where('email', $forgejoEmail)->first();
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $forgejoName !== '' ? $forgejoName : $forgejoLogin,
                    'email' => $forgejoEmail !== '' ? $forgejoEmail : ('forgejo-'.$forgejoUserId.'@forgejo.local'),
                    'password_hash' => Hash::make(Str::random(40)),
                    'status' => 'active',
                    'language' => 'rus',
                ]);
            }
        } elseif ($payload['mode'] === 'connect' && isset($payload['user_id'])) {
            $user = User::query()->find($payload['user_id']);
            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }
        }

        if (! $user) {
            return response()->json(['message' => 'Unable to resolve user.'], 422);
        }

        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);
        $expiresAt = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;

        $user->forgejo_user_id = $forgejoUserId;
        $user->forgejo_username = $forgejoLogin;
        $user->forgejo_access_token = $accessToken;
        $user->forgejo_refresh_token = (string) ($tokenData['refresh_token'] ?? '');
        $user->forgejo_token_expires_at = $expiresAt;
        $user->forgejo_connected_at = now();
        $user->save();

        if ($payload['mode'] === 'connect') {
            return response()->json([
                'status' => 'connected',
                'forgejo_scopes' => $forgejoMeta['scopes'] ?? '',
                'forgejo_required_scopes' => $forgejoMeta['accepted_scopes'] ?? '',
                'user' => $user,
            ]);
        }

        $plainTextToken = $user->createToken('forgejo')->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'forgejo_scopes' => $forgejoMeta['scopes'] ?? '',
            'forgejo_required_scopes' => $forgejoMeta['accepted_scopes'] ?? '',
            'user' => $user,
        ]);
    }

    private function stateKey(string $state): string
    {
        return 'forgejo_oauth_state:'.$state;
    }
}

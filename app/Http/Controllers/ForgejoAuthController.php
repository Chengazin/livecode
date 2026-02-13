<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ForgejoService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $user = Auth::guard('sanctum')->user();
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

        $mode = (string) ($payload['mode'] ?? '');
        if (! in_array($mode, ['login', 'connect'], true)) {
            return response()->json(['message' => 'Invalid state payload.'], 422);
        }

        if ($mode === 'connect') {
            $stateUserId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
            $currentUser = Auth::guard('sanctum')->user();

            if (! $currentUser) {
                return response()->json(['message' => 'Unauthorized.'], 401);
            }

            if ($stateUserId <= 0 || (int) $currentUser->user_id !== $stateUserId) {
                return response()->json(['message' => 'State user mismatch.'], 403);
            }
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
        $forgejoEmail = trim(Str::lower((string) ($forgejoUser['email'] ?? '')));
        $forgejoName = (string) ($forgejoUser['full_name'] ?? $forgejoLogin);

        if ($forgejoUserId === 0 || $forgejoLogin === '') {
            return response()->json(['message' => 'Invalid user payload.'], 502);
        }

        $user = null;

        if ($mode === 'login') {
            $user = User::query()->where('forgejo_user_id', $forgejoUserId)->first();

            if (! $user && $forgejoEmail !== '') {
                $emailMatchedUser = $this->findUserByEmail($forgejoEmail);

                if ($emailMatchedUser) {
                    $existingForgejoId = $emailMatchedUser->forgejo_user_id;
                    if ($existingForgejoId !== null && (int) $existingForgejoId !== $forgejoUserId) {
                        return response()->json([
                            'message' => 'Email is already linked to another Forgejo account.',
                        ], 409);
                    }

                    if (! $this->autoLinkByEmailEnabled()) {
                        return response()->json([
                            'message' => 'Local account already exists for this email. Sign in and connect Forgejo manually.',
                        ], 409);
                    }

                    $user = $emailMatchedUser;
                }
            }

            if (! $user) {
                try {
                    $user = User::query()->create([
                        'name' => $forgejoName !== '' ? $forgejoName : $forgejoLogin,
                        'email' => $this->resolveNewUserEmail($forgejoEmail, $forgejoUserId),
                        'password_hash' => Hash::make(Str::random(40)),
                        'status' => 'active',
                        'language' => 'rus',
                    ]);
                } catch (QueryException $e) {
                    return response()->json([
                        'message' => 'Unable to create user for this Forgejo account.',
                    ], 409);
                }
            }
        } elseif ($mode === 'connect' && isset($payload['user_id'])) {
            $user = User::query()->find($payload['user_id']);
            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }
        }

        if (! $user) {
            return response()->json(['message' => 'Unable to resolve user.'], 422);
        }

        if ($mode === 'login' && $user->status !== 'active') {
            return response()->json(['message' => 'User is not active.'], 403);
        }

        if (
            $mode === 'connect'
            && $user->forgejo_user_id !== null
            && (int) $user->forgejo_user_id !== $forgejoUserId
        ) {
            return response()->json([
                'message' => 'Current account is already linked to another Forgejo user.',
            ], 409);
        }

        $linkedUser = User::query()
            ->where('forgejo_user_id', $forgejoUserId)
            ->where('user_id', '!=', $user->user_id)
            ->first();

        if ($linkedUser) {
            return response()->json([
                'message' => 'This Forgejo account is already connected to another local user.',
            ], 409);
        }

        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);
        $expiresAt = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;

        try {
            $user->forgejo_user_id = $forgejoUserId;
            $user->forgejo_username = $forgejoLogin;
            $user->forgejo_access_token = $accessToken;
            $user->forgejo_refresh_token = (string) ($tokenData['refresh_token'] ?? '');
            $user->forgejo_token_expires_at = $expiresAt;
            $user->forgejo_connected_at = now();
            $user->save();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Unable to link Forgejo account. It may already be linked elsewhere.',
            ], 409);
        }

        if ($mode === 'connect') {
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

    private function autoLinkByEmailEnabled(): bool
    {
        return (bool) config('services.forgejo.auto_link_by_email', false);
    }

    private function findUserByEmail(string $email): ?User
    {
        $normalized = trim(Str::lower($email));
        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();
    }

    private function resolveNewUserEmail(string $forgejoEmail, int $forgejoUserId): string
    {
        if ($forgejoEmail !== '' && filter_var($forgejoEmail, FILTER_VALIDATE_EMAIL)) {
            return $forgejoEmail;
        }

        $base = 'forgejo-'.$forgejoUserId;
        $email = $base.'@forgejo.local';
        $suffix = 2;

        while (User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
            $email = $base.'-'.$suffix.'@forgejo.local';
            $suffix++;
        }

        return $email;
    }

    private function stateKey(string $state): string
    {
        return 'forgejo_oauth_state:'.$state;
    }
}

<?php

namespace App\Services\ForgejoAuth;

use App\Models\User;
use App\Services\ForgejoService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ForgejoAuthWorkflowService
{
    public function __construct(
        private readonly ForgejoOAuthStateService $oauthState
    ) {
    }

    public function start(string $mode, ?User $currentUser, ForgejoService $forgejo): ForgejoAuthResult
    {
        if ($mode === 'connect' && ! $currentUser) {
            return new ForgejoAuthResult(401, ['message' => 'Unauthorized.']);
        }

        $state = $this->oauthState->issue($mode, $currentUser);

        return new ForgejoAuthResult(200, [
            'auth_url' => $forgejo->buildAuthorizationUrl($state['state']),
            'state' => $state['state'],
            'mode' => $mode,
        ], $state['binding']);
    }

    public function callback(
        string $code,
        string $state,
        string $bindingFromRequest,
        ?User $currentUser,
        ForgejoService $forgejo
    ): ForgejoAuthResult {
        $payload = $this->oauthState->consume($state);
        if (! is_array($payload)) {
            return new ForgejoAuthResult(422, ['message' => 'Invalid state.']);
        }

        $expectedBinding = (string) ($payload['binding'] ?? '');
        if ($expectedBinding === '' || $bindingFromRequest === '' || ! hash_equals($expectedBinding, $bindingFromRequest)) {
            return new ForgejoAuthResult(422, ['message' => 'Invalid state.']);
        }

        $mode = (string) ($payload['mode'] ?? '');
        if (! in_array($mode, ['login', 'connect'], true)) {
            return new ForgejoAuthResult(422, ['message' => 'Invalid state payload.']);
        }

        if ($mode === 'connect') {
            $stateUserId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
            if (! $currentUser) {
                return new ForgejoAuthResult(401, ['message' => 'Unauthorized.']);
            }

            if ($stateUserId <= 0 || (int) $currentUser->user_id !== $stateUserId) {
                return new ForgejoAuthResult(403, ['message' => 'State user mismatch.']);
            }
        }

        try {
            $tokenData = $forgejo->exchangeCode($code);
            $accessToken = (string) ($tokenData['access_token'] ?? '');

            if ($accessToken === '') {
                return new ForgejoAuthResult(502, ['message' => 'Invalid token response.']);
            }

            $forgejoMeta = $forgejo->fetchUserWithMeta($accessToken);
            $forgejoUser = $forgejoMeta['user'];
        } catch (RuntimeException $e) {
            return new ForgejoAuthResult(502, ['message' => $e->getMessage()]);
        }

        $forgejoUserId = (int) ($forgejoUser['id'] ?? 0);
        $forgejoLogin = (string) ($forgejoUser['login'] ?? '');
        $forgejoEmail = trim(Str::lower((string) ($forgejoUser['email'] ?? '')));
        $forgejoName = (string) ($forgejoUser['full_name'] ?? $forgejoLogin);

        if ($forgejoUserId === 0 || $forgejoLogin === '') {
            return new ForgejoAuthResult(502, ['message' => 'Invalid user payload.']);
        }

        $user = null;

        if ($mode === 'login') {
            $user = $this->resolveUserForLogin($forgejoUserId, $forgejoEmail, $forgejoName, $forgejoLogin);
            if ($user instanceof ForgejoAuthResult) {
                return $user;
            }
        } elseif ($mode === 'connect' && isset($payload['user_id'])) {
            $user = User::query()->find($payload['user_id']);
            if (! $user) {
                return new ForgejoAuthResult(404, ['message' => 'User not found.']);
            }
        }

        if (! $user) {
            return new ForgejoAuthResult(422, ['message' => 'Unable to resolve user.']);
        }

        if ($mode === 'login' && $user->status !== 'active') {
            return new ForgejoAuthResult(403, ['message' => 'User is not active.']);
        }

        if (
            $mode === 'connect'
            && $user->forgejo_user_id !== null
            && (int) $user->forgejo_user_id !== $forgejoUserId
        ) {
            return new ForgejoAuthResult(409, [
                'message' => 'Current account is already linked to another Forgejo user.',
            ]);
        }

        $linkedUser = User::query()
            ->where('forgejo_user_id', $forgejoUserId)
            ->where('user_id', '!=', $user->user_id)
            ->first();

        if ($linkedUser) {
            return new ForgejoAuthResult(409, [
                'message' => 'This Forgejo account is already connected to another local user.',
            ]);
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
            return new ForgejoAuthResult(409, [
                'message' => 'Unable to link Forgejo account. It may already be linked elsewhere.',
            ]);
        }

        if ($mode === 'connect') {
            return new ForgejoAuthResult(200, [
                'status' => 'connected',
                'forgejo_scopes' => $forgejoMeta['scopes'] ?? '',
                'forgejo_required_scopes' => $forgejoMeta['accepted_scopes'] ?? '',
                'user' => $user,
            ]);
        }

        $plainTextToken = $user->createToken('forgejo')->plainTextToken;

        return new ForgejoAuthResult(200, [
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

    private function resolveUserForLogin(
        int $forgejoUserId,
        string $forgejoEmail,
        string $forgejoName,
        string $forgejoLogin
    ): User|ForgejoAuthResult {
        $user = User::query()->where('forgejo_user_id', $forgejoUserId)->first();

        if (! $user && $forgejoEmail !== '') {
            $emailMatchedUser = $this->findUserByEmail($forgejoEmail);
            if ($emailMatchedUser) {
                $existingForgejoId = $emailMatchedUser->forgejo_user_id;
                if ($existingForgejoId !== null && (int) $existingForgejoId !== $forgejoUserId) {
                    return new ForgejoAuthResult(409, [
                        'message' => 'Email is already linked to another Forgejo account.',
                    ]);
                }

                if (! $this->autoLinkByEmailEnabled()) {
                    return new ForgejoAuthResult(409, [
                        'message' => 'Local account already exists for this email. Sign in and connect Forgejo manually.',
                    ]);
                }

                $user = $emailMatchedUser;
            }
        }

        if ($user) {
            return $user;
        }

        try {
            return User::query()->create([
                'name' => $forgejoName !== '' ? $forgejoName : $forgejoLogin,
                'email' => $this->resolveNewUserEmail($forgejoEmail, $forgejoUserId),
                'password_hash' => Hash::make(Str::random(40)),
                'status' => 'active',
                'language' => 'rus',
            ]);
        } catch (QueryException $e) {
            return new ForgejoAuthResult(409, [
                'message' => 'Unable to create user for this Forgejo account.',
            ]);
        }
    }
}

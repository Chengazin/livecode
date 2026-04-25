<?php

namespace App\Services\ForgejoAuth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class ForgejoOAuthStateService
{
    private const OAUTH_STATE_COOKIE = 'forgejo_oauth_binding';
    private const OAUTH_STATE_TTL_MINUTES = 10;

    /**
     * @return array{state: string, binding: string}
     */
    public function issue(string $mode, ?User $user): array
    {
        $state = Str::random(40);
        $binding = Str::random(64);

        Cache::put(
            $this->stateKey($state),
            [
                'mode' => $mode,
                'user_id' => $user?->user_id,
                'created_at' => now()->toISOString(),
                'binding' => $binding,
            ],
            now()->addMinutes(self::OAUTH_STATE_TTL_MINUTES)
        );

        return [
            'state' => $state,
            'binding' => $binding,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consume(string $state): ?array
    {
        $payload = Cache::pull($this->stateKey($state));
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    public function bindingFromRequest(Request $request): string
    {
        $binding = trim((string) $request->cookie(self::OAUTH_STATE_COOKIE, ''));

        if ($binding === '' && app()->environment('testing')) {
            $binding = trim((string) $request->header('X-Forgejo-OAuth-Binding', ''));
        }

        return $binding;
    }

    public function issueBindingCookie(Request $request, string $binding): SymfonyCookie
    {
        $domain = trim((string) config('session.domain', ''));
        $sameSite = $this->cookieSameSiteValue();
        $secure = (bool) (config('session.secure') ?? false);

        if ($request->isSecure()) {
            $secure = true;
        }

        return cookie(
            self::OAUTH_STATE_COOKIE,
            $binding,
            self::OAUTH_STATE_TTL_MINUTES,
            '/',
            $domain !== '' ? $domain : null,
            $secure,
            true,
            false,
            $sameSite
        );
    }

    public function clearBindingCookie(Request $request): SymfonyCookie
    {
        $domain = trim((string) config('session.domain', ''));

        return cookie()->forget(
            self::OAUTH_STATE_COOKIE,
            '/',
            $domain !== '' ? $domain : null
        );
    }

    private function cookieSameSiteValue(): string
    {
        $candidate = strtolower(trim((string) config('session.same_site', 'lax')));

        return in_array($candidate, ['lax', 'strict', 'none'], true)
            ? $candidate
            : 'lax';
    }

    private function stateKey(string $state): string
    {
        return 'forgejo_oauth_state:'.$state;
    }
}

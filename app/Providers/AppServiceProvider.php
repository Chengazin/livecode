<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request): array {
            $normalizedEmail = Str::lower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(20)->by('auth-login-ip:'.$request->ip()),
                Limit::perMinute(8)->by('auth-login-email:'.sha1($normalizedEmail.'|'.$request->ip())),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request): array {
            return [
                Limit::perMinute(10)->by('auth-register-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('oauth-start', function (Request $request): array {
            $user = $request->user();
            $actor = $user
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();

            return [
                Limit::perMinute(20)->by('oauth-start:'.$actor),
            ];
        });

        RateLimiter::for('invitation-accept', function (Request $request): array {
            $inviteToken = Str::lower(trim((string) $request->input('invite_token', '')));

            return [
                Limit::perMinute(30)->by('invite-accept-ip:'.$request->ip()),
                Limit::perMinute(10)->by('invite-accept-token:'.sha1($inviteToken.'|'.$request->ip())),
            ];
        });

        RateLimiter::for('project-write', function (Request $request): array {
            $user = $request->user();
            $actor = $user
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();
            $projectId = (int) ($request->route('projectId') ?? 0);
            $scope = $projectId > 0 ? 'project:'.$projectId : 'project:na';

            return [
                Limit::perMinute(240)->by('project-write:'.$scope.':'.$actor),
                Limit::perMinute(600)->by('project-write:global:'.$actor),
            ];
        });

        RateLimiter::for('realtime-chat', function (Request $request): array {
            $user = $request->user();
            $actor = $user
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();
            $projectId = (int) ($request->route('projectId') ?? 0);
            $scope = $projectId > 0 ? 'project:'.$projectId : 'project:na';

            return [
                Limit::perMinute(90)->by('realtime-chat:'.$scope.':'.$actor),
                Limit::perMinute(180)->by('realtime-chat:global:'.$actor),
            ];
        });

        RateLimiter::for('realtime-editor', function (Request $request): array {
            $user = $request->user();
            $actor = $user
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();
            $projectId = (int) ($request->route('projectId') ?? 0);
            $scope = $projectId > 0 ? 'project:'.$projectId : 'project:na';

            return [
                Limit::perMinute(2400)->by('realtime-editor:'.$scope.':'.$actor),
                Limit::perMinute(3600)->by('realtime-editor:global:'.$actor),
            ];
        });
    }
}

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['api', 'auth:sanctum'],
        ]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $skipTextTransformsForEditorPayloads = static function ($request): bool {
            return $request->is('api/projects/*/filesystem/file')
                || $request->is('api/projects/*/realtime/editor-state')
                || $request->is('api/projects/*/realtime/editor-sync');
        };
        $middleware->trimStrings([$skipTextTransformsForEditorPayloads]);
        $middleware->convertEmptyStringsToNull([$skipTextTransformsForEditorPayloads]);
        $middleware->redirectGuestsTo(function () {
            return '/';
        });
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

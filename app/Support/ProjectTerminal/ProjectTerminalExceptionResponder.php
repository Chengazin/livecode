<?php

namespace App\Support\ProjectTerminal;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ProjectTerminalExceptionResponder
{
    public function fromException(InvalidArgumentException $exception): JsonResponse
    {
        return match ($exception->getMessage()) {
            'access_denied', 'shared_forbidden' => response()->json(['message' => 'Access denied.'], 403),
            'limit_reached' => response()->json(['message' => 'Terminal session limit reached.'], 429),
            'invalid_shell' => response()->json(['message' => 'Unsupported shell.'], 422),
            'invalid_cwd', 'cwd_not_found', 'cwd_not_directory' => response()->json(['message' => 'Invalid terminal directory.'], 422),
            'session_closed' => response()->json(['message' => 'Terminal session is closed.'], 409),
            'terminal_secret_missing' => response()->json(['message' => 'Terminal shared secret is not configured.'], 500),
            'terminal_gateway_missing' => response()->json(['message' => 'Terminal gateway URL is not configured.'], 500),
            'invalid_project_root' => response()->json(['message' => 'Invalid project root.'], 500),
            default => throw $exception,
        };
    }
}

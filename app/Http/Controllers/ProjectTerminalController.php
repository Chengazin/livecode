<?php

namespace App\Http\Controllers;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Services\ProjectAccessService;
use App\Services\ProjectTerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProjectTerminalController extends Controller
{
    public function index(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal
    ): JsonResponse {
        if (! $this->terminalEnabled()) {
            return response()->json(['message' => 'Terminal feature is disabled.'], 503);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $sessions = $terminal->listVisibleSessions($project, $user);

        return response()->json([
            'status' => 'ok',
            'gateway_ws_url' => (string) config('terminal.gateway_ws_url', ''),
            'sessions' => array_map(fn ($session) => $this->serializeSession($session), $sessions),
        ]);
    }

    public function store(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal
    ): JsonResponse {
        if (! $this->terminalEnabled()) {
            return response()->json(['message' => 'Terminal feature is disabled.'], 503);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'cwd' => ['nullable', 'string', 'max:2048'],
            'shared' => ['nullable', 'boolean'],
            'shell' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $session = $terminal->createSession($project, $user, $data);
        } catch (InvalidArgumentException $exception) {
            return $this->terminalExceptionToResponse($exception);
        }

        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.terminal.session.updated',
            ['session' => $this->serializeSession($session)]
        ));

        return response()->json([
            'status' => 'ok',
            'session' => $this->serializeSession($session),
        ], 201);
    }

    public function ticket(
        Request $request,
        int $projectId,
        int $terminalSessionId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal
    ): JsonResponse {
        if (! $this->terminalEnabled()) {
            return response()->json(['message' => 'Terminal feature is disabled.'], 503);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $session = $terminal->findProjectSession($project, $terminalSessionId);
        if (! $session) {
            return response()->json(['message' => 'Terminal session not found.'], 404);
        }

        try {
            $ticket = $terminal->issueConnectTicket($project, $session, $user);
        } catch (InvalidArgumentException $exception) {
            return $this->terminalExceptionToResponse($exception);
        }

        return response()->json([
            'status' => 'ok',
            'session' => $this->serializeSession($ticket['session']),
            'ticket' => $ticket['ticket'],
            'ws_url' => $ticket['ws_url'],
            'expires_at' => $ticket['expires_at'],
        ]);
    }

    public function close(
        Request $request,
        int $projectId,
        int $terminalSessionId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal
    ): JsonResponse {
        if (! $this->terminalEnabled()) {
            return response()->json(['message' => 'Terminal feature is disabled.'], 503);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $session = $terminal->findProjectSession($project, $terminalSessionId);
        if (! $session) {
            return response()->json(['message' => 'Terminal session not found.'], 404);
        }

        try {
            $closed = $terminal->closeSession($project, $session, $user);
        } catch (InvalidArgumentException $exception) {
            return $this->terminalExceptionToResponse($exception);
        }

        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.terminal.session.updated',
            ['session' => $this->serializeSession($closed)]
        ));

        return response()->json([
            'status' => 'ok',
            'session' => $this->serializeSession($closed),
        ]);
    }

    public function gatewayClose(
        Request $request,
        int $terminalSessionId,
        ProjectTerminalService $terminal
    ): JsonResponse {
        if (! $this->terminalEnabled()) {
            return response()->json(['message' => 'Terminal feature is disabled.'], 503);
        }

        $secret = $this->terminalCallbackSecret();
        if ($secret === '') {
            return response()->json(['message' => 'Terminal gateway callback secret is not configured.'], 500);
        }

        if (! $this->isGatewayCallbackAuthorized($request, $secret)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:closed'],
            'reason' => ['nullable', 'string', 'max:120'],
            'runtime' => ['nullable', 'string', 'max:32'],
            'exit_code' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'signal' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'closed_at' => ['nullable', 'date'],
            'request_user_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $session = $terminal->findSessionById($terminalSessionId);
        if (! $session) {
            return response()->json(['message' => 'Terminal session not found.'], 404);
        }

        $closed = $terminal->closeSessionFromGateway($session, $data);

        $this->broadcastSafely(new ProjectRealtimeEvent(
            (int) $closed->project_id,
            (int) $closed->user_id,
            'realtime.terminal.session.updated',
            ['session' => $this->serializeSession($closed)]
        ));

        return response()->json([
            'status' => 'ok',
            'session' => $this->serializeSession($closed),
        ]);
    }

    /**
     * @param mixed $session
     * @return array<string, mixed>
     */
    private function serializeSession($session): array
    {
        $user = $session->user;

        return [
            'terminal_session_id' => (int) $session->terminal_session_id,
            'project_id' => (int) $session->project_id,
            'user_id' => (int) $session->user_id,
            'name' => (string) $session->name,
            'shell' => (string) ($session->shell ?? ''),
            'cwd' => (string) ($session->cwd ?? '/'),
            'shared' => (bool) $session->shared,
            'status' => (string) ($session->status ?? ''),
            'last_activity_at' => $session->last_activity_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
            'updated_at' => $session->updated_at?->toISOString(),
            'user' => [
                'user_id' => (int) ($user->user_id ?? 0),
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
            ],
        ];
    }

    private function terminalExceptionToResponse(InvalidArgumentException $exception): JsonResponse
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

    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable) {
            // Broadcast availability should not break API write paths.
        }
    }

    private function terminalEnabled(): bool
    {
        return (bool) config('terminal.enabled', true);
    }

    private function terminalCallbackSecret(): string
    {
        return trim((string) config('terminal.gateway_callback_secret', config('terminal.shared_secret', '')));
    }

    private function isGatewayCallbackAuthorized(Request $request, string $secret): bool
    {
        $headerName = (string) config('terminal.gateway_callback_header', 'X-Terminal-Gateway-Secret');
        $provided = trim((string) $request->header($headerName, ''));

        if ($provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }
}

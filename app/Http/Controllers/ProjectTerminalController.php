<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectTerminal\CreateTerminalSessionRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\ProjectTerminalService;
use App\Support\ProjectTerminal\ProjectTerminalExceptionResponder;
use App\Support\ProjectTerminal\ProjectTerminalGatewayAuthorizer;
use App\Support\ProjectTerminal\ProjectTerminalSessionSerializer;
use App\Support\ProjectTerminal\ProjectTerminalValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProjectTerminalController extends Controller
{
    public function index(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal,
        ProjectTerminalSessionSerializer $serializer
    ): JsonResponse {
        $disabled = $this->featureDisabledResponse();
        if ($disabled instanceof JsonResponse) {
            return $disabled;
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = $this->resolveWritableProject($projectId, $user, $access);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $sessions = $terminal->listVisibleSessions($project, $user);

        return response()->json([
            'status' => 'ok',
            'gateway_ws_url' => (string) config('terminal.gateway_ws_url', ''),
            'sessions' => array_map(fn ($session) => $serializer->serialize($session), $sessions),
        ]);
    }

    public function store(
        CreateTerminalSessionRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal,
        ProjectTerminalSessionSerializer $serializer,
        ProjectTerminalExceptionResponder $exceptionResponder
    ): JsonResponse {
        $disabled = $this->featureDisabledResponse();
        if ($disabled instanceof JsonResponse) {
            return $disabled;
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = $this->resolveWritableProject($projectId, $user, $access);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        try {
            $session = $terminal->createSession($project, $user, $request->payload());
        } catch (InvalidArgumentException $exception) {
            return $exceptionResponder->fromException($exception);
        }

        return response()->json([
            'status' => 'ok',
            'session' => $serializer->serialize($session),
        ], 201);
    }

    public function ticket(
        Request $request,
        int $projectId,
        int $terminalSessionId,
        ProjectAccessService $access,
        ProjectTerminalService $terminal,
        ProjectTerminalSessionSerializer $serializer,
        ProjectTerminalExceptionResponder $exceptionResponder
    ): JsonResponse {
        $disabled = $this->featureDisabledResponse();
        if ($disabled instanceof JsonResponse) {
            return $disabled;
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = $this->resolveWritableProject($projectId, $user, $access);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $session = $terminal->findProjectSession($project, $terminalSessionId);
        if (! $session) {
            return response()->json(['message' => 'Terminal session not found.'], 404);
        }

        try {
            $ticket = $terminal->issueConnectTicket($project, $session, $user);
        } catch (InvalidArgumentException $exception) {
            return $exceptionResponder->fromException($exception);
        }

        return response()->json([
            'status' => 'ok',
            'session' => $serializer->serialize($ticket['session']),
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
        ProjectTerminalService $terminal,
        ProjectTerminalSessionSerializer $serializer,
        ProjectTerminalExceptionResponder $exceptionResponder
    ): JsonResponse {
        $disabled = $this->featureDisabledResponse();
        if ($disabled instanceof JsonResponse) {
            return $disabled;
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = $this->resolveWritableProject($projectId, $user, $access);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $session = $terminal->findProjectSession($project, $terminalSessionId);
        if (! $session) {
            return response()->json(['message' => 'Terminal session not found.'], 404);
        }

        try {
            $closed = $terminal->closeSession($project, $session, $user);
        } catch (InvalidArgumentException $exception) {
            return $exceptionResponder->fromException($exception);
        }

        return response()->json([
            'status' => 'ok',
            'session' => $serializer->serialize($closed),
        ]);
    }

    public function gatewayClose(
        Request $request,
        int $terminalSessionId,
        ProjectTerminalService $terminal,
        ProjectTerminalGatewayAuthorizer $gatewayAuthorizer,
        ProjectTerminalSessionSerializer $serializer
    ): JsonResponse {
        $disabled = $this->featureDisabledResponse();
        if ($disabled instanceof JsonResponse) {
            return $disabled;
        }

        if ($gatewayAuthorizer->callbackSecret() === '') {
            return response()->json(['message' => 'Terminal gateway callback secret is not configured.'], 500);
        }

        if (! $gatewayAuthorizer->isAuthorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = $request->validate(ProjectTerminalValidation::gatewayCloseRules());

        $session = $terminal->findSessionById($terminalSessionId);
        if (! $session) {
            return response()->json(['message' => 'Terminal session not found.'], 404);
        }

        $closed = $terminal->closeSessionFromGateway($session, $payload);

        return response()->json([
            'status' => 'ok',
            'session' => $serializer->serialize($closed),
        ]);
    }

    private function featureDisabledResponse(): ?JsonResponse
    {
        if ((bool) config('terminal.enabled', true)) {
            return null;
        }

        return response()->json(['message' => 'Terminal feature is disabled.'], 503);
    }

    private function resolveWritableProject(
        int $projectId,
        User $user,
        ProjectAccessService $access
    ): Project|JsonResponse {
        $project = Project::query()->findOrFail($projectId);

        if (! $access->canWriteProject($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $project;
    }
}

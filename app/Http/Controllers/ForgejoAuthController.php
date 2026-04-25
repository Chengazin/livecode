<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgejoAuth\CallbackForgejoAuthRequest;
use App\Http\Requests\ForgejoAuth\StartForgejoAuthRequest;
use App\Services\ForgejoService;
use App\Services\ForgejoAuth\ForgejoAuthWorkflowService;
use App\Services\ForgejoAuth\ForgejoOAuthStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ForgejoAuthController extends Controller
{
    public function start(
        StartForgejoAuthRequest $request,
        ForgejoService $forgejo,
        ForgejoAuthWorkflowService $workflow,
        ForgejoOAuthStateService $oauthState
    ): JsonResponse
    {
        $result = $workflow->start(
            $request->mode(),
            Auth::guard('sanctum')->user(),
            $forgejo
        );

        $response = response()->json($result->payload, $result->status);
        if ($result->stateBinding !== null) {
            $response->withCookie($oauthState->issueBindingCookie($request, $result->stateBinding));
        }

        return $response;
    }

    public function callback(
        CallbackForgejoAuthRequest $request,
        ForgejoService $forgejo,
        ForgejoAuthWorkflowService $workflow,
        ForgejoOAuthStateService $oauthState
    ): JsonResponse
    {
        $result = $workflow->callback(
            $request->code(),
            $request->state(),
            $oauthState->bindingFromRequest($request),
            Auth::guard('sanctum')->user(),
            $forgejo
        );

        return response()
            ->json($result->payload, $result->status)
            ->withCookie($oauthState->clearBindingCookie($request));
    }
}

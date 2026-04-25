<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginAuthRequest;
use App\Http\Requests\Auth\RegisterInitiateAuthRequest;
use App\Http\Requests\Auth\RegisterResendAuthRequest;
use App\Http\Requests\Auth\RegisterVerifyAuthRequest;
use App\Services\Auth\AuthWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function registerInitiate(
        RegisterInitiateAuthRequest $request,
        AuthWorkflowService $workflow
    ): JsonResponse {
        $result = $workflow->registerInitiate($request->payload(), $request->ip());

        return response()->json($result->payload, $result->status);
    }

    public function registerVerify(
        RegisterVerifyAuthRequest $request,
        AuthWorkflowService $workflow
    ): JsonResponse {
        $result = $workflow->registerVerify($request->payload());

        return response()->json($result->payload, $result->status);
    }

    public function registerResendCode(
        RegisterResendAuthRequest $request,
        AuthWorkflowService $workflow
    ): JsonResponse {
        $result = $workflow->registerResendCode($request->verificationId());

        return response()->json($result->payload, $result->status);
    }

    public function getCaptchaConfig(AuthWorkflowService $workflow): JsonResponse
    {
        return response()->json($workflow->captchaConfig());
    }

    public function login(
        LoginAuthRequest $request,
        AuthWorkflowService $workflow
    ): JsonResponse {
        $result = $workflow->login($request->payload());

        return response()->json($result->payload, $result->status);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        return response()->noContent();
    }
}

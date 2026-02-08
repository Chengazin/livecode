<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectInvitationController;
use App\Http\Controllers\ProjectParticipantController;
use App\Http\Controllers\ProjectSnapshotController;
use App\Http\Controllers\ProjectFilesystemController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

    // Создание проекта: POST /api/projects
    Route::apiResource('users', UserController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/{projectId}/filesystem', [ProjectFilesystemController::class, 'handle']);
    Route::apiResource('project-participants', ProjectParticipantController::class);
    Route::apiResource('project-invitations', ProjectInvitationController::class);
    Route::apiResource('project-snapshots', ProjectSnapshotController::class);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('admins', AdminController::class);
});

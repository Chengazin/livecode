<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminProjectInvitationController;
use App\Http\Controllers\AdminProjectParticipantController;
use App\Http\Controllers\AdminProjectSnapshotController;
use App\Http\Controllers\AdminProjectTerminalSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ForgejoAuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectForgejoController;
use App\Http\Controllers\ProjectInvitationController;
use App\Http\Controllers\ProjectParticipantController;
use App\Http\Controllers\ProjectRealtimeController;
use App\Http\Controllers\ProjectTerminalController;
use App\Http\Controllers\ProjectSnapshotController;
use App\Http\Controllers\ProjectFilesystemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/forgejo/oauth/start', [ForgejoAuthController::class, 'start'])->middleware('throttle:oauth-start');
Route::get('/forgejo/oauth/callback', [ForgejoAuthController::class, 'callback']);
Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->middleware('auth:sanctum');
Route::post('/terminal/gateway/sessions/{terminalSessionId}/close', [ProjectTerminalController::class, 'gatewayClose']);

Route::middleware('auth:sanctum')->get('/me', [ProfileController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::patch('/me/profile', [ProfileController::class, 'update']);
    Route::post('/me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/me/avatar', [ProfileController::class, 'deleteAvatar']);

    // Создание проекта: POST /api/projects
    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/{projectId}/filesystem', [ProjectFilesystemController::class, 'handle']);
    Route::get('projects/{projectId}/filesystem/tree', [ProjectFilesystemController::class, 'tree']);
    Route::get('projects/{projectId}/filesystem/file', [ProjectFilesystemController::class, 'readFile']);
    Route::get('projects/{projectId}/filesystem/download', [ProjectFilesystemController::class, 'download']);
    Route::put('projects/{projectId}/filesystem/file', [ProjectFilesystemController::class, 'writeFile']);
    Route::put('projects/{projectId}/filesystem/move', [ProjectFilesystemController::class, 'movePath']);
    Route::delete('projects/{projectId}/filesystem/item', [ProjectFilesystemController::class, 'deletePath']);
    Route::post('projects/{projectId}/forgejo/connect', [ProjectForgejoController::class, 'connect']);
    Route::post('projects/{projectId}/forgejo/save', [ProjectForgejoController::class, 'save']);
    Route::post('projects/{projectId}/forgejo/sync', [ProjectForgejoController::class, 'sync']);
    Route::post('projects/{projectId}/realtime/heartbeat', [ProjectRealtimeController::class, 'heartbeat']);
    Route::get('projects/{projectId}/realtime/presence', [ProjectRealtimeController::class, 'presence']);
    Route::get('projects/{projectId}/realtime/chat', [ProjectRealtimeController::class, 'chatIndex']);
    Route::post('projects/{projectId}/realtime/chat', [ProjectRealtimeController::class, 'chatStore']);
    Route::post('projects/{projectId}/realtime/editor-state', [ProjectRealtimeController::class, 'editorState']);
    Route::post('projects/{projectId}/realtime/editor-sync', [ProjectRealtimeController::class, 'editorSync']);
    Route::get('projects/{projectId}/terminal/sessions', [ProjectTerminalController::class, 'index']);
    Route::post('projects/{projectId}/terminal/sessions', [ProjectTerminalController::class, 'store']);
    Route::post('projects/{projectId}/terminal/sessions/{terminalSessionId}/ticket', [ProjectTerminalController::class, 'ticket']);
    Route::post('projects/{projectId}/terminal/sessions/{terminalSessionId}/close', [ProjectTerminalController::class, 'close']);
    Route::post('project-invitations/accept', [ProjectInvitationController::class, 'accept'])->middleware('throttle:invitation-accept');
    Route::apiResource('project-participants', ProjectParticipantController::class);
    Route::apiResource('project-invitations', ProjectInvitationController::class);
    Route::apiResource('project-snapshots', ProjectSnapshotController::class);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('admins', AdminController::class);
    Route::apiResource('admin-projects', AdminProjectController::class);
    Route::apiResource('admin-project-participants', AdminProjectParticipantController::class);
    Route::apiResource('admin-project-invitations', AdminProjectInvitationController::class);
    Route::apiResource('admin-project-snapshots', AdminProjectSnapshotController::class);
    Route::apiResource('admin-terminal-sessions', AdminProjectTerminalSessionController::class);
});

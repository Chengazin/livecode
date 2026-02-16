<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ForgejoAuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectForgejoController;
use App\Http\Controllers\ProjectInvitationController;
use App\Http\Controllers\ProjectParticipantController;
use App\Http\Controllers\ProjectRealtimeController;
use App\Http\Controllers\ProjectSnapshotController;
use App\Http\Controllers\ProjectFilesystemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/forgejo/oauth/start', [ForgejoAuthController::class, 'start']);
Route::get('/forgejo/oauth/callback', [ForgejoAuthController::class, 'callback']);
Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->middleware('auth:sanctum');

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
    Route::post('project-invitations/accept', [ProjectInvitationController::class, 'accept']);
    Route::apiResource('project-participants', ProjectParticipantController::class);
    Route::apiResource('project-invitations', ProjectInvitationController::class);
    Route::apiResource('project-snapshots', ProjectSnapshotController::class);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('admins', AdminController::class);
});

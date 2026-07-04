<?php
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminProjectInvitationController;
use App\Http\Controllers\AdminProjectParticipantController;
use App\Http\Controllers\AdminProjectTerminalSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ForgejoAuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectForgejoController;
use App\Http\Controllers\ProjectGitController;
use App\Http\Controllers\ProjectInfoController;
use App\Http\Controllers\ProjectInvitationController;
use App\Http\Controllers\ProjectParticipantController;
use App\Http\Controllers\ProjectRealtimeController;
use App\Http\Controllers\ProjectSpeechController;
use App\Http\Controllers\ProjectTerminalController;
use App\Http\Controllers\ProjectFilesystemController;
use App\Http\Controllers\ProjectCodeCommentController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register/initiate', [AuthController::class, 'registerInitiate'])->middleware('throttle:auth-register');
Route::post('/auth/register/verify', [AuthController::class, 'registerVerify'])->middleware('throttle:auth-register');
Route::post('/auth/register/resend-code', [AuthController::class, 'registerResendCode'])->middleware('throttle:auth-register');
Route::get('/auth/captcha-config', [AuthController::class, 'getCaptchaConfig']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/forgejo/oauth/start', [ForgejoAuthController::class, 'start'])->middleware('throttle:oauth-start');
Route::get('/forgejo/oauth/callback', [ForgejoAuthController::class, 'callback']);
Route::post('/terminal/gateway/sessions/{terminalSessionId}/close', [ProjectTerminalController::class, 'gatewayClose']);
Route::middleware('auth:sanctum')->get('/me', [ProfileController::class, 'show']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::patch('/me/profile', [ProfileController::class, 'update']);
    Route::post('/me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/me/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::apiResource('projects', ProjectController::class);
    Route::get('projects/{projectId}/info', [ProjectInfoController::class, 'show']);
    Route::post('projects/{projectId}/git/branch', [ProjectGitController::class, 'createBranch']);
    Route::post('projects/{projectId}/git/checkout-branch', [ProjectGitController::class, 'checkoutBranch']);
    Route::post('projects/{projectId}/git/checkout-commit', [ProjectGitController::class, 'checkoutCommit']);
    Route::post('projects/{projectId}/filesystem', [ProjectFilesystemController::class, 'handle'])
        ->middleware('throttle:project-write');
    Route::get('projects/{projectId}/filesystem/tree', [ProjectFilesystemController::class, 'tree']);
    Route::get('projects/{projectId}/filesystem/file', [ProjectFilesystemController::class, 'readFile']);
    Route::get('projects/{projectId}/filesystem/download', [ProjectFilesystemController::class, 'download']);
    Route::put('projects/{projectId}/filesystem/file', [ProjectFilesystemController::class, 'writeFile'])
        ->middleware('throttle:project-write');
    Route::put('projects/{projectId}/filesystem/move', [ProjectFilesystemController::class, 'movePath'])
        ->middleware('throttle:project-write');
    Route::delete('projects/{projectId}/filesystem/item', [ProjectFilesystemController::class, 'deletePath'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/forgejo/connect', [ProjectForgejoController::class, 'connect']);
    Route::post('projects/{projectId}/forgejo/save', [ProjectForgejoController::class, 'save']);
    Route::post('projects/{projectId}/forgejo/pull-request', [ProjectForgejoController::class, 'pullRequest']);
    Route::post('projects/{projectId}/forgejo/sync', [ProjectForgejoController::class, 'sync']);
    Route::post('projects/{projectId}/realtime/heartbeat', [ProjectRealtimeController::class, 'heartbeat']);
    Route::get('projects/{projectId}/realtime/presence', [ProjectRealtimeController::class, 'presence']);
    Route::get('projects/{projectId}/realtime/chat', [ProjectRealtimeController::class, 'chatIndex']);
    Route::post('projects/{projectId}/realtime/chat', [ProjectRealtimeController::class, 'chatStore'])
        ->middleware('throttle:realtime-chat');
    Route::patch('projects/{projectId}/realtime/chat/{messageId}', [ProjectRealtimeController::class, 'chatUpdate'])
        ->middleware('throttle:realtime-chat');
    Route::delete('projects/{projectId}/realtime/chat/{messageId}', [ProjectRealtimeController::class, 'chatDestroy'])
        ->middleware('throttle:realtime-chat');
    Route::post('projects/{projectId}/speech/transcribe', [ProjectSpeechController::class, 'transcribe'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/realtime/editor-state', [ProjectRealtimeController::class, 'editorState'])
        ->middleware('throttle:realtime-editor');
    Route::post('projects/{projectId}/realtime/editor-sync', [ProjectRealtimeController::class, 'editorSync'])
        ->middleware('throttle:realtime-editor');
    Route::get('projects/{projectId}/code-comments', [ProjectCodeCommentController::class, 'index']);
    Route::post('projects/{projectId}/code-comments', [ProjectCodeCommentController::class, 'store'])
        ->middleware('throttle:project-write');
    Route::delete('projects/{projectId}/code-comments/{commentId}', [ProjectCodeCommentController::class, 'destroy'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/terminal/sessions', [ProjectTerminalController::class, 'store'])
        ->middleware('throttle:project-write');
    Route::get('projects/{projectId}/terminal/sessions', [ProjectTerminalController::class, 'index']);
    Route::post('projects/{projectId}/terminal/sessions/{terminalSessionId}/ticket', [ProjectTerminalController::class, 'ticket'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/terminal/sessions/{terminalSessionId}/close', [ProjectTerminalController::class, 'close'])
        ->middleware('throttle:project-write');

    // Project Tasks
    Route::get('projects/{projectId}/tasks/stats', [ProjectTaskController::class, 'getStats']);
    Route::get('projects/{projectId}/tasks', [ProjectTaskController::class, 'index']);
    Route::post('projects/{projectId}/tasks', [ProjectTaskController::class, 'store'])
        ->middleware('throttle:project-write');
    Route::get('projects/{projectId}/tasks/{projectTaskId}', [ProjectTaskController::class, 'show']);
    Route::patch('projects/{projectId}/tasks/{projectTaskId}', [ProjectTaskController::class, 'update'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/tasks/{projectTaskId}/assign', [ProjectTaskController::class, 'assignTask'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/tasks/{projectTaskId}/start', [ProjectTaskController::class, 'startTask'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/tasks/{projectTaskId}/complete', [ProjectTaskController::class, 'completeTask'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/tasks/{projectTaskId}/reopen', [ProjectTaskController::class, 'reopenTask'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/tasks/{projectTaskId}/close', [ProjectTaskController::class, 'completeTask'])
        ->middleware('throttle:project-write');
    Route::post('projects/{projectId}/tasks/{projectTaskId}/unassign', [ProjectTaskController::class, 'unassignTask'])
        ->middleware('throttle:project-write');
    Route::delete('projects/{projectId}/tasks/{projectTaskId}', [ProjectTaskController::class, 'destroy'])
        ->middleware('throttle:project-write');

    Route::post('project-invitations/accept', [ProjectInvitationController::class, 'accept'])->middleware('throttle:invitation-accept');
    Route::apiResource('project-participants', ProjectParticipantController::class);
    Route::apiResource('project-invitations', ProjectInvitationController::class);


    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('notifications/{notificationId}/mark-as-read', [NotificationController::class, 'markAsRead']);
    Route::patch('notifications/{notificationId}/mark-as-unread', [NotificationController::class, 'markAsUnread']);
    Route::post('notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{notificationId}', [NotificationController::class, 'delete']);
    Route::delete('notifications', [NotificationController::class, 'deleteAll']);
});
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('admins', AdminController::class);
    Route::apiResource('admin-projects', AdminProjectController::class);
    Route::apiResource('admin-project-participants', AdminProjectParticipantController::class);
    Route::apiResource('admin-project-invitations', AdminProjectInvitationController::class);
    Route::apiResource('admin-terminal-sessions', AdminProjectTerminalSessionController::class);
});

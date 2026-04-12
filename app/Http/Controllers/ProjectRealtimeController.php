<?php

namespace App\Http\Controllers;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessService;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProjectRealtimeController extends Controller
{
    private const DEFAULT_AVATAR_PRESET = 'robot';

    private const PRESENCE_TTL_SECONDS = 20;

    private const CHAT_TTL_SECONDS = 43200;

    private const CHAT_MAX_MESSAGES = 200;

    private const EDITOR_STATE_TTL_SECONDS = 43200;

    private const EDITOR_MAX_CONTENT_LENGTH = 524288;

    private const EDITOR_MAX_INSERT_LENGTH = 524288;

    private const EDITOR_MAX_HISTORY = 500;

    private const EDITOR_LOCK_TTL_SECONDS = 5;

    public function heartbeat(
        Request $request,
        int $projectId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:2048'],
            'cursor_row' => ['nullable', 'integer', 'min:0'],
            'cursor_column' => ['nullable', 'integer', 'min:0'],
            'selection_start_row' => ['nullable', 'integer', 'min:0'],
            'selection_start_column' => ['nullable', 'integer', 'min:0'],
            'selection_end_row' => ['nullable', 'integer', 'min:0'],
            'selection_end_column' => ['nullable', 'integer', 'min:0'],
        ]);

        $key = $this->presenceKey($project->project_id);

        try {
            [$entries, $current] = $this->withRealtimeCacheLock(
                $this->presenceLockKey($project->project_id),
                function () use ($key, $user, $data): array {
                    $entries = Cache::get($key, []);
                    if (! is_array($entries)) {
                        $entries = [];
                    }

                    $entries[(string) $user->user_id] = [
                        'user_id' => (int) $user->user_id,
                        'name' => (string) ($user->name ?: $user->email ?: 'User #'.$user->user_id),
                        'avatar_preset' => $this->normalizeAvatarPreset($user->avatar_preset),
                        'avatar_url' => $this->resolveAvatarUrl($user),
                        'path' => array_key_exists('path', $data) ? (string) ($data['path'] ?? '') : '',
                        'cursor_row' => array_key_exists('cursor_row', $data) ? $data['cursor_row'] : null,
                        'cursor_column' => array_key_exists('cursor_column', $data) ? $data['cursor_column'] : null,
                        'selection_start_row' => array_key_exists('selection_start_row', $data) ? $data['selection_start_row'] : null,
                        'selection_start_column' => array_key_exists('selection_start_column', $data) ? $data['selection_start_column'] : null,
                        'selection_end_row' => array_key_exists('selection_end_row', $data) ? $data['selection_end_row'] : null,
                        'selection_end_column' => array_key_exists('selection_end_column', $data) ? $data['selection_end_column'] : null,
                        'seen_at' => now()->timestamp,
                    ];

                    $entries = $this->prunePresence($entries);

                    Cache::put(
                        $key,
                        $entries,
                        now()->addSeconds(self::PRESENCE_TTL_SECONDS + 5)
                    );

                    $current = $entries[(string) $user->user_id] ?? null;

                    return [$entries, is_array($current) ? $current : null];
                }
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Realtime presence is busy, please retry.',
            ], 423);
        }

        if (is_array($current)) {
            $this->broadcastSafely(new ProjectRealtimeEvent(
                $project->project_id,
                (int) $user->user_id,
                'realtime.presence.updated',
                [
                    'peer' => $this->formatPresenceEntry($current),
                ]
            ));
        }

        return response()->json([
            'status' => 'ok',
            'peers' => $this->formatPresence($entries, (int) $user->user_id),
        ]);
    }

    public function presence(
        Request $request,
        int $projectId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $entries = Cache::get($this->presenceKey($project->project_id), []);
        if (! is_array($entries)) {
            $entries = [];
        }

        $entries = $this->prunePresence($entries);

        return response()->json([
            'status' => 'ok',
            'peers' => $this->formatPresence($entries, (int) $user->user_id),
        ]);
    }

    public function chatIndex(
        Request $request,
        int $projectId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $afterId = (int) ($data['after_id'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);

        $messages = Cache::get($this->chatKey($project->project_id), []);
        if (! is_array($messages)) {
            $messages = [];
        }

        $filtered = array_values(array_filter($messages, function ($message) use ($afterId) {
            return (int) ($message['id'] ?? 0) > $afterId;
        }));

        if (count($filtered) > $limit) {
            $filtered = array_slice($filtered, -$limit);
        }

        $filtered = array_map(function ($message): array {
            return $this->formatChatEntry(is_array($message) ? $message : []);
        }, $filtered);

        $latestId = 0;
        if (count($messages) > 0) {
            $lastMessage = end($messages);
            $latestId = (int) ($lastMessage['id'] ?? 0);
        }

        return response()->json([
            'status' => 'ok',
            'latest_id' => $latestId,
            'messages' => $filtered,
        ]);
    }

    public function chatStore(
        Request $request,
        int $projectId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $text = trim((string) $data['message']);
        if ($text === '') {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        $key = $this->chatKey($project->project_id);
        $seqKey = $this->chatSeqKey($project->project_id);

        try {
            $entry = $this->withRealtimeCacheLock(
                $this->chatLockKey($project->project_id),
                function () use ($seqKey, $key, $user, $text): array {
                    Cache::add($seqKey, 0, now()->addSeconds(self::CHAT_TTL_SECONDS));
                    $messageId = (int) Cache::increment($seqKey);

                    $entry = $this->formatChatEntry([
                        'id' => $messageId,
                        'user_id' => (int) $user->user_id,
                        'user_name' => (string) ($user->name ?: $user->email ?: 'User #'.$user->user_id),
                        'avatar_preset' => $this->normalizeAvatarPreset($user->avatar_preset),
                        'avatar_url' => $this->resolveAvatarUrl($user),
                        'message' => $text,
                        'created_at' => now()->toISOString(),
                        'updated_at' => null,
                    ]);

                    $messages = Cache::get($key, []);
                    if (! is_array($messages)) {
                        $messages = [];
                    }

                    $messages[] = $entry;

                    if (count($messages) > self::CHAT_MAX_MESSAGES) {
                        $messages = array_slice($messages, -self::CHAT_MAX_MESSAGES);
                    }

                    Cache::put($key, $messages, now()->addSeconds(self::CHAT_TTL_SECONDS));

                    return $entry;
                }
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Realtime chat is busy, please retry.',
            ], 423);
        }

        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.chat.message',
            [
                'message' => $entry,
            ]
        ));

        return response()->json([
            'status' => 'ok',
            'message' => $entry,
        ], 201);
    }

    public function chatUpdate(
        Request $request,
        int $projectId,
        int $messageId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $text = trim((string) $data['message']);
        if ($text === '') {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        $key = $this->chatKey($project->project_id);

        try {
            [$entry, $errorCode] = $this->withRealtimeCacheLock(
                $this->chatLockKey($project->project_id),
                function () use ($key, $messageId, $text, $user): array {
                    $messages = Cache::get($key, []);
                    if (! is_array($messages)) {
                        $messages = [];
                    }

                    $updatedEntry = null;
                    $normalizedMessages = [];

                    foreach ($messages as $message) {
                        $item = $this->formatChatEntry(is_array($message) ? $message : []);
                        if ((int) $item['id'] !== $messageId) {
                            $normalizedMessages[] = $item;
                            continue;
                        }

                        if ((int) $item['user_id'] !== (int) $user->user_id) {
                            return [null, 'forbidden'];
                        }

                        $item['message'] = $text;
                        $item['updated_at'] = now()->toISOString();
                        $updatedEntry = $item;
                        $normalizedMessages[] = $item;
                    }

                    if (! is_array($updatedEntry)) {
                        return [null, 'not_found'];
                    }

                    Cache::put($key, $normalizedMessages, now()->addSeconds(self::CHAT_TTL_SECONDS));

                    return [$updatedEntry, null];
                }
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Realtime chat is busy, please retry.',
            ], 423);
        }

        if ($errorCode === 'forbidden') {
            return response()->json(['message' => 'You can edit only your own messages.'], 403);
        }

        if ($errorCode === 'not_found' || ! is_array($entry)) {
            return response()->json(['message' => 'Chat message not found.'], 404);
        }

        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.chat.updated',
            [
                'message' => $entry,
            ]
        ));

        return response()->json([
            'status' => 'ok',
            'message' => $entry,
        ]);
    }

    public function chatDestroy(
        Request $request,
        int $projectId,
        int $messageId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $key = $this->chatKey($project->project_id);

        try {
            [$deletedMessageId, $errorCode] = $this->withRealtimeCacheLock(
                $this->chatLockKey($project->project_id),
                function () use ($key, $messageId, $user): array {
                    $messages = Cache::get($key, []);
                    if (! is_array($messages)) {
                        $messages = [];
                    }

                    $deletedMessageId = 0;
                    $normalizedMessages = [];

                    foreach ($messages as $message) {
                        $item = $this->formatChatEntry(is_array($message) ? $message : []);
                        if ((int) $item['id'] !== $messageId) {
                            $normalizedMessages[] = $item;
                            continue;
                        }

                        if ((int) $item['user_id'] !== (int) $user->user_id) {
                            return [0, 'forbidden'];
                        }

                        $deletedMessageId = (int) $item['id'];
                    }

                    if ($deletedMessageId <= 0) {
                        return [0, 'not_found'];
                    }

                    Cache::put($key, $normalizedMessages, now()->addSeconds(self::CHAT_TTL_SECONDS));

                    return [$deletedMessageId, null];
                }
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Realtime chat is busy, please retry.',
            ], 423);
        }

        if ($errorCode === 'forbidden') {
            return response()->json(['message' => 'You can delete only your own messages.'], 403);
        }

        if ($errorCode === 'not_found' || (int) $deletedMessageId <= 0) {
            return response()->json(['message' => 'Chat message not found.'], 404);
        }

        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.chat.deleted',
            [
                'message_id' => (int) $deletedMessageId,
            ]
        ));

        return response()->json([
            'status' => 'ok',
            'message_id' => (int) $deletedMessageId,
        ]);
    }

    public function editorSync(
        Request $request,
        int $projectId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->canWriteProject($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'client_id' => ['required', 'string', 'min:3', 'max:128'],
            'op_id' => ['required', 'string', 'min:3', 'max:128'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'delete_count' => ['required', 'integer', 'min:0'],
            'insert_text' => ['nullable', 'string', 'max:'.self::EDITOR_MAX_INSERT_LENGTH],
            'cursor_row' => ['nullable', 'integer', 'min:0'],
            'cursor_column' => ['nullable', 'integer', 'min:0'],
        ]);

        $path = (string) $data['path'];
        $clientId = $this->normalizeEditorClientId($user, trim((string) $data['client_id']));
        $opId = trim((string) $data['op_id']);
        $baseRevision = (int) $data['base_revision'];

        return $this->withEditorLock($project->project_id, $path, function () use (
            $project,
            $user,
            $path,
            $clientId,
            $opId,
            $baseRevision,
            $data
        ): JsonResponse {
            $state = $this->readEditorState($project->project_id, $path);
            if ($state === null) {
                $state = $this->createEditorState($path, '');
            }

            $currentRevision = (int) ($state['revision'] ?? 0);
            $currentContent = (string) ($state['content'] ?? '');
            $history = $this->normalizeEditorHistory($state['operations'] ?? []);

            if ($baseRevision !== $currentRevision) {
                $oldestRevision = count($history) > 0
                    ? (int) ($history[0]['revision'] ?? $currentRevision)
                    : $currentRevision;

                $requiresResync = $baseRevision < ($oldestRevision - 1);
                $missing = $requiresResync
                    ? []
                    : $this->historyAfterRevision($history, $baseRevision);

                return response()->json([
                    'status' => 'conflict',
                    'revision' => $currentRevision,
                    'content' => $currentContent,
                    'requires_resync' => $requiresResync,
                    'operations' => $missing,
                ], 409);
            }

            $operation = $this->sanitizeEditorOperation([
                'start' => (int) $data['start'],
                'delete_count' => (int) $data['delete_count'],
                'insert_text' => (string) ($data['insert_text'] ?? ''),
            ], $currentContent);

            if ((int) $operation['delete_count'] === 0 && (string) $operation['insert_text'] === '') {
                return response()->json([
                    'status' => 'noop',
                    'revision' => $currentRevision,
                ]);
            }

            $nextContent = $this->applyEditorOperation($currentContent, $operation);
            if ($this->textLength($nextContent) > self::EDITOR_MAX_CONTENT_LENGTH) {
                return response()->json([
                    'message' => 'Resulting content exceeds maximum allowed length.',
                ], 422);
            }

            $nextRevision = $currentRevision + 1;
            $entry = [
                'revision' => $nextRevision,
                'path' => $path,
                'client_id' => $clientId,
                'op_id' => $opId,
                'user_id' => (int) $user->user_id,
                'operation' => $operation,
                'cursor_row' => array_key_exists('cursor_row', $data) ? $data['cursor_row'] : null,
                'cursor_column' => array_key_exists('cursor_column', $data) ? $data['cursor_column'] : null,
                'created_at' => now()->toISOString(),
            ];

            $history[] = $entry;
            if (count($history) > self::EDITOR_MAX_HISTORY) {
                $history = array_slice($history, -self::EDITOR_MAX_HISTORY);
            }

            $state = [
                'path' => $path,
                'revision' => $nextRevision,
                'content' => $nextContent,
                'operations' => $history,
                'updated_at' => now()->toISOString(),
            ];

            $this->storeEditorState($project->project_id, $path, $state);

            $this->broadcastSafely(new ProjectRealtimeEvent(
                $project->project_id,
                (int) $user->user_id,
                'realtime.editor.operation',
                [
                    'operation' => $entry,
                ]
            ));

            return response()->json([
                'status' => 'ok',
                'revision' => $nextRevision,
                'operation' => $entry,
            ]);
        });
    }

    public function editorState(
        Request $request,
        int $projectId,
        ProjectAccessService $access
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'seed_content' => ['nullable', 'string', 'max:'.self::EDITOR_MAX_CONTENT_LENGTH],
            'reset' => ['nullable', 'boolean'],
        ]);

        $path = (string) $data['path'];
        $reset = (bool) ($data['reset'] ?? false);
        $seedContent = array_key_exists('seed_content', $data)
            ? $this->truncateEditorContent((string) ($data['seed_content'] ?? ''))
            : null;

        if (! $reset && $seedContent === null) {
            $snapshot = $this->readEditorStateSnapshot($project->project_id, $path);

            if ($snapshot !== null) {
                return response()->json([
                    'status' => 'ok',
                    'path' => $path,
                    'revision' => (int) ($snapshot['revision'] ?? 0),
                    'content' => (string) ($snapshot['content'] ?? ''),
                ]);
            }
        }

        return $this->withEditorLock($project->project_id, $path, function () use (
            $project,
            $path,
            $reset,
            $seedContent
        ): JsonResponse {
            $state = $this->readEditorState($project->project_id, $path);

            if ($reset || $state === null) {
                $state = $this->createEditorState($path, $seedContent ?? '');
            } else {
                $state = $this->normalizeEditorState($path, $state);
            }

            $this->storeEditorState($project->project_id, $path, $state);

            return response()->json([
                'status' => 'ok',
                'path' => $path,
                'revision' => (int) ($state['revision'] ?? 0),
                'content' => (string) ($state['content'] ?? ''),
            ]);
        });
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function prunePresence(array $entries): array
    {
        $threshold = now()->timestamp - self::PRESENCE_TTL_SECONDS;

        return array_filter($entries, function ($entry) use ($threshold) {
            return (int) ($entry['seen_at'] ?? 0) >= $threshold;
        });
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function formatPresence(array $entries, int $currentUserId): array
    {
        $list = [];

        foreach ($entries as $entry) {
            if ((int) ($entry['user_id'] ?? 0) === $currentUserId) {
                continue;
            }

            $list[] = $this->formatPresenceEntry($entry);
        }

        usort($list, function ($a, $b) {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_values($list);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function formatPresenceEntry(array $entry): array
    {
        $avatarUrl = trim((string) ($entry['avatar_url'] ?? ''));

        return [
            'user_id' => (int) ($entry['user_id'] ?? 0),
            'name' => (string) ($entry['name'] ?? ''),
            'avatar_preset' => $this->normalizeAvatarPreset(
                is_string($entry['avatar_preset'] ?? null) ? $entry['avatar_preset'] : null
            ),
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            'path' => (string) ($entry['path'] ?? ''),
            'cursor_row' => $entry['cursor_row'] !== null ? (int) $entry['cursor_row'] : null,
            'cursor_column' => $entry['cursor_column'] !== null ? (int) $entry['cursor_column'] : null,
            'selection_start_row' => $entry['selection_start_row'] !== null ? (int) $entry['selection_start_row'] : null,
            'selection_start_column' => $entry['selection_start_column'] !== null ? (int) $entry['selection_start_column'] : null,
            'selection_end_row' => $entry['selection_end_row'] !== null ? (int) $entry['selection_end_row'] : null,
            'selection_end_column' => $entry['selection_end_column'] !== null ? (int) $entry['selection_end_column'] : null,
            'seen_at' => (int) ($entry['seen_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function formatChatEntry(array $entry): array
    {
        $userId = (int) ($entry['user_id'] ?? 0);
        $userName = trim((string) ($entry['user_name'] ?? ''));
        $avatarUrl = trim((string) ($entry['avatar_url'] ?? ''));
        $updatedAt = trim((string) ($entry['updated_at'] ?? ''));

        return [
            'id' => max(0, (int) ($entry['id'] ?? 0)),
            'user_id' => $userId,
            'user_name' => $userName !== '' ? $userName : 'User #'.($userId > 0 ? $userId : '?'),
            'avatar_preset' => $this->normalizeAvatarPreset(
                is_string($entry['avatar_preset'] ?? null) ? $entry['avatar_preset'] : null
            ),
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            'message' => (string) ($entry['message'] ?? ''),
            'created_at' => (string) ($entry['created_at'] ?? ''),
            'updated_at' => $updatedAt !== '' ? $updatedAt : null,
        ];
    }

    private function normalizeAvatarPreset(?string $avatarPreset): string
    {
        $value = trim((string) ($avatarPreset ?? ''));

        return $value !== '' ? $value : self::DEFAULT_AVATAR_PRESET;
    }

    private function resolveAvatarUrl(User $user): ?string
    {
        if ((string) $user->avatar_type !== 'upload') {
            return null;
        }

        $path = trim((string) ($user->avatar_path ?? ''));
        if ($path === '') {
            return null;
        }

        return '/storage/'.ltrim($path, '/');
    }

    private function presenceKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:presence';
    }

    private function presenceLockKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:presence:lock';
    }

    private function chatKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:chat';
    }

    private function chatLockKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:chat:lock';
    }

    private function chatSeqKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:chat:seq';
    }

    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable) {
            // Broadcast availability should not break API write paths.
        }
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function normalizeEditorOperationShape(array $operation): array
    {
        return [
            'start' => max(0, (int) ($operation['start'] ?? 0)),
            'delete_count' => max(0, (int) ($operation['delete_count'] ?? 0)),
            'insert_text' => (string) ($operation['insert_text'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function sanitizeEditorOperation(array $operation, string $content): array
    {
        $normalized = $this->normalizeEditorOperationShape($operation);
        $contentLength = $this->textLength($content);
        $start = max(0, min((int) $normalized['start'], $contentLength));
        $deleteCount = max(0, (int) $normalized['delete_count']);
        $deleteCount = min($deleteCount, max(0, $contentLength - $start));
        $insertText = $this->truncateEditorInsert((string) $normalized['insert_text']);

        return [
            'start' => $start,
            'delete_count' => $deleteCount,
            'insert_text' => $insertText,
        ];
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function applyEditorOperation(string $content, array $operation): string
    {
        $start = max(0, (int) ($operation['start'] ?? 0));
        $deleteCount = max(0, (int) ($operation['delete_count'] ?? 0));
        $insertText = (string) ($operation['insert_text'] ?? '');
        $contentLength = $this->textLength($content);

        if ($start > $contentLength) {
            $start = $contentLength;
        }

        if ($deleteCount > ($contentLength - $start)) {
            $deleteCount = $contentLength - $start;
        }

        $prefix = $this->textSlice($content, 0, $start);
        $suffix = $this->textSlice($content, $start + $deleteCount);

        return $prefix.$insertText.$suffix;
    }

    /**
     * @param array<mixed> $history
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEditorHistory(array $history): array
    {
        $normalized = [];

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $operationRaw = $entry['operation'] ?? null;
            if (! is_array($operationRaw)) {
                continue;
            }

            $operation = $this->normalizeEditorOperationShape($operationRaw);
            $operation['insert_text'] = $this->truncateEditorInsert((string) $operation['insert_text']);

            $normalized[] = [
                'revision' => max(0, (int) ($entry['revision'] ?? 0)),
                'path' => (string) ($entry['path'] ?? ''),
                'client_id' => (string) ($entry['client_id'] ?? ''),
                'op_id' => (string) ($entry['op_id'] ?? ''),
                'user_id' => max(0, (int) ($entry['user_id'] ?? 0)),
                'operation' => $operation,
                'cursor_row' => isset($entry['cursor_row']) ? max(0, (int) $entry['cursor_row']) : null,
                'cursor_column' => isset($entry['cursor_column']) ? max(0, (int) $entry['cursor_column']) : null,
                'created_at' => (string) ($entry['created_at'] ?? ''),
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            return (int) ($left['revision'] ?? 0) <=> (int) ($right['revision'] ?? 0);
        });

        return array_values($normalized);
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, mixed>>
     */
    private function historyAfterRevision(array $history, int $revision): array
    {
        return array_values(array_filter($history, function (array $entry) use ($revision): bool {
            return (int) ($entry['revision'] ?? 0) > $revision;
        }));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function normalizeEditorState(string $path, array $state): array
    {
        $content = (string) ($state['content'] ?? '');
        $content = $this->truncateEditorContent($content);

        return [
            'path' => $path,
            'revision' => max(0, (int) ($state['revision'] ?? 0)),
            'content' => $content,
            'operations' => $this->normalizeEditorHistory(is_array($state['operations'] ?? null) ? $state['operations'] : []),
            'updated_at' => (string) ($state['updated_at'] ?? now()->toISOString()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createEditorState(string $path, string $content): array
    {
        return [
            'path' => $path,
            'revision' => 0,
            'content' => $this->truncateEditorContent($content),
            'operations' => [],
            'updated_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readEditorStateSnapshot(int $projectId, string $path): ?array
    {
        $state = Cache::get($this->editorDocKey($projectId, $path));
        if (! is_array($state)) {
            return null;
        }

        return [
            'path' => $path,
            'revision' => max(0, (int) ($state['revision'] ?? 0)),
            'content' => $this->truncateEditorContent((string) ($state['content'] ?? '')),
            'updated_at' => (string) ($state['updated_at'] ?? now()->toISOString()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readEditorState(int $projectId, string $path): ?array
    {
        $state = Cache::get($this->editorDocKey($projectId, $path));
        if (! is_array($state)) {
            return null;
        }

        return $this->normalizeEditorState($path, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function storeEditorState(int $projectId, string $path, array $state): void
    {
        Cache::put(
            $this->editorDocKey($projectId, $path),
            $state,
            now()->addSeconds(self::EDITOR_STATE_TTL_SECONDS)
        );
    }

    /**
     * @param callable(): JsonResponse $callback
     */
    private function withEditorLock(int $projectId, string $path, callable $callback): JsonResponse
    {
        $lock = Cache::lock(
            $this->editorDocLockKey($projectId, $path),
            self::EDITOR_LOCK_TTL_SECONDS
        );
        $acquired = false;

        try {
            $acquired = (bool) $lock->get();
            if (! $acquired) {
                return response()->json([
                    'message' => 'Editor sync is busy, please retry.',
                ], 423);
            }

            return $callback();
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Realtime editor sync failed.',
            ], 500);
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Ignore release failures; lock TTL bounds stale locks.
                }
            }
        }
    }

    private function editorDocKey(int $projectId, string $path): string
    {
        return 'project:'.$projectId.':realtime:editor:doc:'.sha1($path);
    }

    private function editorDocLockKey(int $projectId, string $path): string
    {
        return 'project:'.$projectId.':realtime:editor:doc:lock:'.sha1($path);
    }

    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    private function withRealtimeCacheLock(string $lockKey, callable $callback)
    {
        $store = Cache::getStore();
        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return Cache::lock($lockKey, 3)->block(2, $callback);
    }

    private function normalizeEditorClientId(User $user, string $clientId): string
    {
        $normalized = strtolower(trim($clientId));
        $normalized = preg_replace('/[^a-z0-9._:-]/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            $normalized = 'client';
        }

        if (strlen($normalized) > 96) {
            $normalized = substr($normalized, 0, 96);
        }

        return 'u'.((int) $user->user_id).':'.$normalized;
    }

    private function truncateEditorInsert(string $value): string
    {
        if ($this->textLength($value) <= self::EDITOR_MAX_INSERT_LENGTH) {
            return $value;
        }

        return $this->textSlice($value, 0, self::EDITOR_MAX_INSERT_LENGTH);
    }

    private function truncateEditorContent(string $value): string
    {
        if ($this->textLength($value) <= self::EDITOR_MAX_CONTENT_LENGTH) {
            return $value;
        }

        return $this->textSlice($value, 0, self::EDITOR_MAX_CONTENT_LENGTH);
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function textSlice(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            if ($length === null) {
                return (string) mb_substr($value, $start, null, 'UTF-8');
            }

            return (string) mb_substr($value, $start, $length, 'UTF-8');
        }

        if ($length === null) {
            return substr($value, $start);
        }

        return substr($value, $start, $length);
    }
}

<?php

namespace App\Http\Controllers;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Services\ProjectAccessService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProjectRealtimeController extends Controller
{
    private const PRESENCE_TTL_SECONDS = 20;
    private const CHAT_TTL_SECONDS = 43200;
    private const CHAT_MAX_MESSAGES = 200;
    private const EDITOR_STATE_TTL_SECONDS = 43200;
    private const EDITOR_MAX_CONTENT_LENGTH = 524288;
    private const EDITOR_MAX_INSERT_LENGTH = 524288;
    private const EDITOR_MAX_HISTORY = 500;
    private const EDITOR_LOCK_TTL_SECONDS = 5;
    private const EDITOR_LOCK_WAIT_SECONDS = 2;

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
        ]);

        $key = $this->presenceKey($project->project_id);
        $entries = Cache::get($key, []);
        if (! is_array($entries)) {
            $entries = [];
        }

        $entries[(string) $user->user_id] = [
            'user_id' => (int) $user->user_id,
            'name' => (string) ($user->name ?: $user->email ?: 'User #'.$user->user_id),
            'path' => array_key_exists('path', $data) ? (string) ($data['path'] ?? '') : '',
            'cursor_row' => array_key_exists('cursor_row', $data) ? $data['cursor_row'] : null,
            'cursor_column' => array_key_exists('cursor_column', $data) ? $data['cursor_column'] : null,
            'seen_at' => now()->timestamp,
        ];

        $entries = $this->prunePresence($entries);

        Cache::put(
            $key,
            $entries,
            now()->addSeconds(self::PRESENCE_TTL_SECONDS + 5)
        );

        $current = $entries[(string) $user->user_id] ?? null;
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

        Cache::put(
            $this->presenceKey($project->project_id),
            $entries,
            now()->addSeconds(self::PRESENCE_TTL_SECONDS + 5)
        );

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

        $seqKey = $this->chatSeqKey($project->project_id);
        Cache::add($seqKey, 0, now()->addSeconds(self::CHAT_TTL_SECONDS));
        $messageId = (int) Cache::increment($seqKey);

        $entry = [
            'id' => $messageId,
            'user_id' => (int) $user->user_id,
            'user_name' => (string) ($user->name ?: $user->email ?: 'User #'.$user->user_id),
            'message' => $text,
            'created_at' => now()->toISOString(),
        ];

        $key = $this->chatKey($project->project_id);
        $messages = Cache::get($key, []);
        if (! is_array($messages)) {
            $messages = [];
        }

        $messages[] = $entry;

        if (count($messages) > self::CHAT_MAX_MESSAGES) {
            $messages = array_slice($messages, -self::CHAT_MAX_MESSAGES);
        }

        Cache::put($key, $messages, now()->addSeconds(self::CHAT_TTL_SECONDS));

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
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'client_id' => ['required', 'string', 'max:128'],
            'op_id' => ['required', 'string', 'max:128'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'delete_count' => ['required', 'integer', 'min:0'],
            'insert_text' => ['nullable', 'string', 'max:'.self::EDITOR_MAX_INSERT_LENGTH],
            'cursor_row' => ['nullable', 'integer', 'min:0'],
            'cursor_column' => ['nullable', 'integer', 'min:0'],
        ]);

        $path = (string) $data['path'];
        $clientId = trim((string) $data['client_id']);
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
        return [
            'user_id' => (int) ($entry['user_id'] ?? 0),
            'name' => (string) ($entry['name'] ?? ''),
            'path' => (string) ($entry['path'] ?? ''),
            'cursor_row' => $entry['cursor_row'] !== null ? (int) $entry['cursor_row'] : null,
            'cursor_column' => $entry['cursor_column'] !== null ? (int) $entry['cursor_column'] : null,
            'seen_at' => (int) ($entry['seen_at'] ?? 0),
        ];
    }

    private function presenceKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:presence';
    }

    private function chatKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:chat';
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
    private function sanitizeEditorOperation(array $operation, string $content): array
    {
        $contentLength = $this->textLength($content);
        $start = max(0, min((int) ($operation['start'] ?? 0), $contentLength));
        $deleteCount = max(0, (int) ($operation['delete_count'] ?? 0));
        $deleteCount = min($deleteCount, max(0, $contentLength - $start));
        $insertText = (string) ($operation['insert_text'] ?? '');
        $insertText = $this->truncateEditorInsert($insertText);

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

            $operation = $this->sanitizeEditorOperation($operationRaw, '');

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

        try {
            $response = $lock->block(self::EDITOR_LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Editor sync is busy, please retry.',
            ], 423);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Realtime editor sync failed.',
            ], 500);
        }

        return $response;
    }

    private function editorDocKey(int $projectId, string $path): string
    {
        return 'project:'.$projectId.':realtime:editor:doc:'.sha1($path);
    }

    private function editorDocLockKey(int $projectId, string $path): string
    {
        return 'project:'.$projectId.':realtime:editor:doc:lock:'.sha1($path);
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

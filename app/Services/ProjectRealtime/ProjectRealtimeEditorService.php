<?php
namespace App\Services\ProjectRealtime;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
class ProjectRealtimeEditorService
{
    /**
     * @param array<string, mixed> $data
     */
    public function sync(int $projectId, User $user, array $data): ProjectRealtimeResult
    {
        $path = (string) $data['path'];
        $clientId = $this->normalizeEditorClientId($user, trim((string) $data['client_id']));
        $opId = trim((string) $data['op_id']);
        $baseRevision = (int) $data['base_revision'];
        return $this->withEditorLock($projectId, $path, function () use (
            $projectId,
            $user,
            $path,
            $clientId,
            $opId,
            $baseRevision,
            $data
        ): ProjectRealtimeResult {
            $state = $this->readEditorState($projectId, $path);
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
                return new ProjectRealtimeResult(
                    409,
                    [
                        'status' => 'conflict',
                        'revision' => $currentRevision,
                        'content' => $currentContent,
                        'requires_resync' => $requiresResync,
                        'operations' => $missing,
                    ]
                );
            }
            $operation = $this->sanitizeEditorOperation([
                'start' => (int) $data['start'],
                'delete_count' => (int) $data['delete_count'],
                'insert_text' => (string) ($data['insert_text'] ?? ''),
            ], $currentContent);
            if ((int) $operation['delete_count'] === 0 && (string) $operation['insert_text'] === '') {
                return new ProjectRealtimeResult(
                    200,
                    [
                        'status' => 'noop',
                        'revision' => $currentRevision,
                    ]
                );
            }
            $nextContent = $this->applyEditorOperation($currentContent, $operation);
            if ($this->textLength($nextContent) > ProjectRealtimeLimits::EDITOR_MAX_CONTENT_LENGTH) {
                return new ProjectRealtimeResult(
                    422,
                    ['message' => 'Resulting content exceeds maximum allowed length.']
                );
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
            if (count($history) > ProjectRealtimeLimits::EDITOR_MAX_HISTORY) {
                $history = array_slice($history, -ProjectRealtimeLimits::EDITOR_MAX_HISTORY);
            }
            $state = [
                'path' => $path,
                'revision' => $nextRevision,
                'content' => $nextContent,
                'operations' => $history,
                'updated_at' => now()->toISOString(),
            ];
            $this->storeEditorState($projectId, $path, $state);
            return new ProjectRealtimeResult(
                200,
                [
                    'status' => 'ok',
                    'revision' => $nextRevision,
                    'operation' => $entry,
                ],
                'realtime.editor.operation',
                ['operation' => $entry]
            );
        });
    }
    /**
     * @param array<string, mixed> $data
     */
    public function state(int $projectId, bool $canWriteProject, array $data): ProjectRealtimeResult
    {
        $path = (string) $data['path'];
        $reset = (bool) ($data['reset'] ?? false);
        $seedContent = array_key_exists('seed_content', $data)
            ? $this->truncateEditorContent((string) ($data['seed_content'] ?? ''))
            : null;
        if (! $canWriteProject) {
            if ($reset) {
                return new ProjectRealtimeResult(
                    403,
                    ['message' => 'Access denied.']
                );
            }
            $snapshot = $this->readEditorStateSnapshot($projectId, $path);
            if ($snapshot !== null) {
                return new ProjectRealtimeResult(
                    200,
                    [
                        'status' => 'ok',
                        'path' => $path,
                        'revision' => (int) ($snapshot['revision'] ?? 0),
                        'content' => (string) ($snapshot['content'] ?? ''),
                    ]
                );
            }
            return new ProjectRealtimeResult(
                200,
                [
                    'status' => 'ok',
                    'path' => $path,
                    'revision' => 0,
                    'content' => (string) ($seedContent ?? ''),
                ]
            );
        }
        if (! $reset && $seedContent === null) {
            $snapshot = $this->readEditorStateSnapshot($projectId, $path);

            if ($snapshot !== null) {
                return new ProjectRealtimeResult(
                    200,
                    [
                        'status' => 'ok',
                        'path' => $path,
                        'revision' => (int) ($snapshot['revision'] ?? 0),
                        'content' => (string) ($snapshot['content'] ?? ''),
                    ]
                );
            }
        }
        return $this->withEditorLock($projectId, $path, function () use (
            $projectId,
            $path,
            $reset,
            $seedContent
        ): ProjectRealtimeResult {
            $state = $this->readEditorState($projectId, $path);

            if ($reset || $state === null) {
                $state = $this->createEditorState($path, $seedContent ?? '');
            } else {
                $state = $this->normalizeEditorState($path, $state);
            }
            $this->storeEditorState($projectId, $path, $state);
            return new ProjectRealtimeResult(
                200,
                [
                    'status' => 'ok',
                    'path' => $path,
                    'revision' => (int) ($state['revision'] ?? 0),
                    'content' => (string) ($state['content'] ?? ''),
                ]
            );
        });
    }
    /**
     * @param callable(): ProjectRealtimeResult $callback
     */
    private function withEditorLock(int $projectId, string $path, callable $callback): ProjectRealtimeResult
    {
        $lock = Cache::lock(
            $this->editorDocLockKey($projectId, $path),
            ProjectRealtimeLimits::EDITOR_LOCK_TTL_SECONDS
        );
        $acquired = false;
        try {
            $acquired = (bool) $lock->get();
            if (! $acquired) {
                return new ProjectRealtimeResult(
                    423,
                    ['message' => 'Editor sync is busy, please retry.']
                );
            }
            return $callback();
        } catch (\Throwable) {
            return new ProjectRealtimeResult(
                500,
                ['message' => 'Realtime editor sync failed.']
            );
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
     */    private function createEditorState(string $path, string $content): array
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
            now()->addSeconds(ProjectRealtimeLimits::EDITOR_STATE_TTL_SECONDS)
        );
    }
    private function editorDocKey(int $projectId, string $path): string
    {
        return 'project:'.$projectId.':realtime:editor:doc:'.sha1($path);
    }
    private function editorDocLockKey(int $projectId, string $path): string
    {
        return 'project:'.$projectId.':realtime:editor:doc:lock:'.sha1($path);
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
        if ($this->textLength($value) <= ProjectRealtimeLimits::EDITOR_MAX_INSERT_LENGTH) {
            return $value;
        }
        return $this->textSlice($value, 0, ProjectRealtimeLimits::EDITOR_MAX_INSERT_LENGTH);
    }
    private function truncateEditorContent(string $value): string
    {
        if ($this->textLength($value) <= ProjectRealtimeLimits::EDITOR_MAX_CONTENT_LENGTH) {
            return $value;
        }

        return $this->textSlice($value, 0, ProjectRealtimeLimits::EDITOR_MAX_CONTENT_LENGTH);
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

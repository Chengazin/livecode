<?php
namespace App\Services\ProjectRealtime;
use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
class ProjectRealtimePresenceService
{
    public function __construct(
        private readonly ProjectRealtimePayloadFormatter $formatter
    ) {
    }
    /**
     * @param array<string, mixed> $data
     */
    public function heartbeat(int $projectId, User $user, array $data): ProjectRealtimeResult
    {
        $key = $this->presenceKey($projectId);

        try {
            [$entries, $current] = $this->withRealtimeCacheLock(
                $this->presenceLockKey($projectId),
                function () use ($key, $user, $data): array {
                    $entries = Cache::get($key, []);
                    if (! is_array($entries)) {
                        $entries = [];
                    }
                    $entries[(string) $user->user_id] = [
                        'user_id' => (int) $user->user_id,
                        'name' => $this->formatter->userDisplayName($user),
                        'avatar_preset' => $this->formatter->normalizeAvatarPreset($user->avatar_preset),
                        'avatar_url' => $this->formatter->resolveAvatarUrl($user),
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
                        now()->addSeconds(ProjectRealtimeLimits::PRESENCE_TTL_SECONDS + 5)
                    );
                    $current = $entries[(string) $user->user_id] ?? null;
                    return [$entries, is_array($current) ? $current : null];
                }
            );
        } catch (LockTimeoutException) {
            return new ProjectRealtimeResult(
                423,
                ['message' => 'Realtime presence is busy, please retry.']
            );
        }
        if (is_array($current)) {
            return new ProjectRealtimeResult(
                200,
                [
                    'status' => 'ok',
                    'peers' => $this->formatter->formatPresence($entries, (int) $user->user_id),
                ],
                'realtime.presence.updated',
                ['peer' => $this->formatter->formatPresenceEntry($current)]
            );
        }
        return new ProjectRealtimeResult(
            200,
            [
                'status' => 'ok',
                'peers' => $this->formatter->formatPresence($entries, (int) $user->user_id),
            ]
        );
    }
    public function presence(int $projectId, int $currentUserId): ProjectRealtimeResult
    {
        $entries = Cache::get($this->presenceKey($projectId), []);
        if (! is_array($entries)) {
            $entries = [];
        }
        $entries = $this->prunePresence($entries);
        return new ProjectRealtimeResult(
            200,
            [
                'status' => 'ok',
                'peers' => $this->formatter->formatPresence($entries, $currentUserId),
            ]
        );
    }
    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */    private function prunePresence(array $entries): array
    {
        $threshold = now()->timestamp - ProjectRealtimeLimits::PRESENCE_TTL_SECONDS;

        return array_filter($entries, function ($entry) use ($threshold): bool {
            return (int) ($entry['seen_at'] ?? 0) >= $threshold;
        });
    }
    private function presenceKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:presence';
    }
    private function presenceLockKey(int $projectId): string
    {
        return 'project:'.$projectId.':realtime:presence:lock';
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
}

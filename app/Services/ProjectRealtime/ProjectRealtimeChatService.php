<?php

namespace App\Services\ProjectRealtime;

use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class ProjectRealtimeChatService
{
    public function __construct(
        private readonly ProjectRealtimePayloadFormatter $formatter
    ) {
    }

    public function index(int $projectId, int $afterId, int $limit): ProjectRealtimeResult
    {
        $messages = Cache::get($this->chatKey($projectId), []);
        if (! is_array($messages)) {
            $messages = [];
        }

        $filtered = array_values(array_filter($messages, function ($message) use ($afterId): bool {
            return is_array($message) && (int) ($message['id'] ?? 0) > $afterId;
        }));

        if (count($filtered) > $limit) {
            $filtered = array_slice($filtered, -$limit);
        }

        $filtered = array_map(function ($message): array {
            return $this->formatter->formatChatEntry(is_array($message) ? $message : []);
        }, $filtered);

        $latestId = 0;
        if (count($messages) > 0) {
            $lastMessage = end($messages);
            $latestId = is_array($lastMessage)
                ? (int) ($lastMessage['id'] ?? 0)
                : 0;
        }

        return new ProjectRealtimeResult(
            200,
            [
                'status' => 'ok',
                'latest_id' => $latestId,
                'messages' => $filtered,
            ]
        );
    }

    public function store(int $projectId, User $user, string $message): ProjectRealtimeResult
    {
        $text = trim($message);
        if ($text === '') {
            return new ProjectRealtimeResult(
                422,
                ['message' => 'Message cannot be empty.']
            );
        }

        $key = $this->chatKey($projectId);
        $seqKey = $this->chatSeqKey($projectId);

        try {
            $entry = $this->withRealtimeCacheLock(
                $this->chatLockKey($projectId),
                function () use ($seqKey, $key, $user, $text): array {
                    Cache::add($seqKey, 0, now()->addSeconds(ProjectRealtimeLimits::CHAT_TTL_SECONDS));
                    $messageId = (int) Cache::increment($seqKey);

                    $entry = $this->formatter->formatChatEntry([
                        'id' => $messageId,
                        'user_id' => (int) $user->user_id,
                        'user_name' => $this->formatter->userDisplayName($user),
                        'avatar_preset' => $this->formatter->normalizeAvatarPreset($user->avatar_preset),
                        'avatar_url' => $this->formatter->resolveAvatarUrl($user),
                        'message' => $text,
                        'created_at' => now()->toISOString(),
                        'updated_at' => null,
                    ]);

                    $messages = Cache::get($key, []);
                    if (! is_array($messages)) {
                        $messages = [];
                    }

                    $messages[] = $entry;

                    if (count($messages) > ProjectRealtimeLimits::CHAT_MAX_MESSAGES) {
                        $messages = array_slice($messages, -ProjectRealtimeLimits::CHAT_MAX_MESSAGES);
                    }

                    Cache::put($key, $messages, now()->addSeconds(ProjectRealtimeLimits::CHAT_TTL_SECONDS));

                    return $entry;
                }
            );
        } catch (LockTimeoutException) {
            return new ProjectRealtimeResult(
                423,
                ['message' => 'Realtime chat is busy, please retry.']
            );
        }

        return new ProjectRealtimeResult(
            201,
            [
                'status' => 'ok',
                'message' => $entry,
            ],
            'realtime.chat.message',
            ['message' => $entry]
        );
    }

    public function update(int $projectId, int $messageId, User $user, string $message): ProjectRealtimeResult
    {
        $text = trim($message);
        if ($text === '') {
            return new ProjectRealtimeResult(
                422,
                ['message' => 'Message cannot be empty.']
            );
        }

        $key = $this->chatKey($projectId);

        try {
            [$entry, $errorCode] = $this->withRealtimeCacheLock(
                $this->chatLockKey($projectId),
                function () use ($key, $messageId, $text, $user): array {
                    $messages = Cache::get($key, []);
                    if (! is_array($messages)) {
                        $messages = [];
                    }

                    $updatedEntry = null;
                    $normalizedMessages = [];

                    foreach ($messages as $message) {
                        $item = $this->formatter->formatChatEntry(is_array($message) ? $message : []);
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

                    Cache::put($key, $normalizedMessages, now()->addSeconds(ProjectRealtimeLimits::CHAT_TTL_SECONDS));

                    return [$updatedEntry, null];
                }
            );
        } catch (LockTimeoutException) {
            return new ProjectRealtimeResult(
                423,
                ['message' => 'Realtime chat is busy, please retry.']
            );
        }

        if ($errorCode === 'forbidden') {
            return new ProjectRealtimeResult(
                403,
                ['message' => 'You can edit only your own messages.']
            );
        }

        if ($errorCode === 'not_found' || ! is_array($entry)) {
            return new ProjectRealtimeResult(
                404,
                ['message' => 'Chat message not found.']
            );
        }

        return new ProjectRealtimeResult(
            200,
            [
                'status' => 'ok',
                'message' => $entry,
            ],
            'realtime.chat.updated',
            ['message' => $entry]
        );
    }

    public function destroy(int $projectId, int $messageId, User $user): ProjectRealtimeResult
    {
        $key = $this->chatKey($projectId);

        try {
            [$deletedMessageId, $errorCode] = $this->withRealtimeCacheLock(
                $this->chatLockKey($projectId),
                function () use ($key, $messageId, $user): array {
                    $messages = Cache::get($key, []);
                    if (! is_array($messages)) {
                        $messages = [];
                    }

                    $deletedMessageId = 0;
                    $normalizedMessages = [];

                    foreach ($messages as $message) {
                        $item = $this->formatter->formatChatEntry(is_array($message) ? $message : []);
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

                    Cache::put($key, $normalizedMessages, now()->addSeconds(ProjectRealtimeLimits::CHAT_TTL_SECONDS));

                    return [$deletedMessageId, null];
                }
            );
        } catch (LockTimeoutException) {
            return new ProjectRealtimeResult(
                423,
                ['message' => 'Realtime chat is busy, please retry.']
            );
        }

        if ($errorCode === 'forbidden') {
            return new ProjectRealtimeResult(
                403,
                ['message' => 'You can delete only your own messages.']
            );
        }

        if ($errorCode === 'not_found' || (int) $deletedMessageId <= 0) {
            return new ProjectRealtimeResult(
                404,
                ['message' => 'Chat message not found.']
            );
        }

        return new ProjectRealtimeResult(
            200,
            [
                'status' => 'ok',
                'message_id' => (int) $deletedMessageId,
            ],
            'realtime.chat.deleted',
            ['message_id' => (int) $deletedMessageId]
        );
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

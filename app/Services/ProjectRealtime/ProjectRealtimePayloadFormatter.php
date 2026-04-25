<?php

namespace App\Services\ProjectRealtime;

use App\Models\User;

class ProjectRealtimePayloadFormatter
{
    public function userDisplayName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        return 'User #'.$user->user_id;
    }

    public function normalizeAvatarPreset(?string $avatarPreset): string
    {
        $value = trim((string) ($avatarPreset ?? ''));

        return $value !== '' ? $value : ProjectRealtimeLimits::DEFAULT_AVATAR_PRESET;
    }

    public function resolveAvatarUrl(User $user): ?string
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

    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    public function formatPresence(array $entries, int $currentUserId): array
    {
        $list = [];

        foreach ($entries as $entry) {
            if ((int) ($entry['user_id'] ?? 0) === $currentUserId) {
                continue;
            }

            $list[] = $this->formatPresenceEntry($entry);
        }

        usort($list, function (array $left, array $right): int {
            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return array_values($list);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function formatPresenceEntry(array $entry): array
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
            'cursor_row' => $this->nullableInt($entry, 'cursor_row'),
            'cursor_column' => $this->nullableInt($entry, 'cursor_column'),
            'selection_start_row' => $this->nullableInt($entry, 'selection_start_row'),
            'selection_start_column' => $this->nullableInt($entry, 'selection_start_column'),
            'selection_end_row' => $this->nullableInt($entry, 'selection_end_row'),
            'selection_end_column' => $this->nullableInt($entry, 'selection_end_column'),
            'seen_at' => (int) ($entry['seen_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function formatChatEntry(array $entry): array
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

    /**
     * @param array<string, mixed> $entry
     */
    private function nullableInt(array $entry, string $key): ?int
    {
        if (! array_key_exists($key, $entry) || $entry[$key] === null) {
            return null;
        }

        return (int) $entry[$key];
    }
}

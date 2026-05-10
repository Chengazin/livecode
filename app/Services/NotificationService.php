<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * @var array<int, string>
     */
    private static array $localeCache = [];

    /**
     * Send a notification to a user
     *
     * @param int $userId
     * @param string $type
     * @param string $title
     * @param string $message
     * @param array $data Optional additional data
     * @return Notification
     */
    public static function sendNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        array $data = []
    ): Notification {
        $notification = Notification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
        ]);

        // Broadcast notification in real-time via Reverb
        self::broadcastNotification($userId, $notification);

        Log::info('Notification sent', [
            'notification_id' => $notification->notification_id,
            'user_id' => $userId,
            'type' => $type,
        ]);

        return $notification;
    }

    /**
     * Send localized notification to a user.
     */
    public static function sendLocalizedNotification(
        int $userId,
        string $type,
        string $titleKey,
        string $messageKey,
        array $replace = [],
        array $data = []
    ): Notification {
        $locale = self::resolveLocaleForUser($userId);

        return self::sendNotification(
            userId: $userId,
            type: $type,
            title: self::translateWithLocale($titleKey, $replace, $locale),
            message: self::translateWithLocale($messageKey, $replace, $locale),
            data: $data
        );
    }

    /**
     * Send invitation received notification
     */
    public static function notifyInvitationReceived(
        int $recipientUserId,
        int $senderUserId,
        int $projectId,
        string $projectName
    ): Notification {
        $sender = User::find($senderUserId);
        $senderName = $sender?->name ?? 'Someone';

        return self::sendLocalizedNotification(
            userId: $recipientUserId,
            type: Notification::TYPE_INVITATION_RECEIVED,
            titleKey: 'notifications.templates.invitation_received.title',
            messageKey: 'notifications.templates.invitation_received.message',
            replace: [
                'sender' => $senderName,
                'project' => $projectName,
            ],
            data: [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'sender_id' => $senderUserId,
                'sender_name' => $senderName,
            ]
        );
    }

    /**
     * Send invitation accepted notification
     */
    public static function notifyInvitationAccepted(
        int $projectOwnerId,
        int $acceptingUserId,
        int $projectId,
        string $projectName
    ): Notification {
        $acceptingUser = User::find($acceptingUserId);
        $userName = $acceptingUser?->name ?? 'Someone';

        return self::sendLocalizedNotification(
            userId: $projectOwnerId,
            type: Notification::TYPE_INVITATION_ACCEPTED,
            titleKey: 'notifications.templates.invitation_accepted.title',
            messageKey: 'notifications.templates.invitation_accepted.message',
            replace: [
                'user' => $userName,
                'project' => $projectName,
            ],
            data: [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'user_id' => $acceptingUserId,
                'user_name' => $userName,
            ]
        );
    }

    /**
     * Send invitation declined notification
     */
    public static function notifyInvitationDeclined(
        int $projectOwnerId,
        int $decliningUserId,
        int $projectId,
        string $projectName
    ): Notification {
        $decliningUser = User::find($decliningUserId);
        $userName = $decliningUser?->name ?? 'Someone';

        return self::sendLocalizedNotification(
            userId: $projectOwnerId,
            type: Notification::TYPE_INVITATION_DECLINED,
            titleKey: 'notifications.templates.invitation_declined.title',
            messageKey: 'notifications.templates.invitation_declined.message',
            replace: [
                'user' => $userName,
                'project' => $projectName,
            ],
            data: [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'user_id' => $decliningUserId,
                'user_name' => $userName,
            ]
        );
    }

    /**
     * Send participant added notification
     */
    public static function notifyParticipantAdded(
        int $newParticipantUserId,
        int $addedByUserId,
        int $projectId,
        string $projectName,
        string $role = 'editor'
    ): Notification {
        $addedByUser = User::find($addedByUserId);
        $addedByName = $addedByUser?->name ?? 'Owner';
        $locale = self::resolveLocaleForUser($newParticipantUserId);
        $roleLabel = self::translateRoleWithLocale($role, $locale);

        return self::sendNotification(
            userId: $newParticipantUserId,
            type: Notification::TYPE_PARTICIPANT_ADDED,
            title: self::translateWithLocale('notifications.templates.participant_added.title', [], $locale),
            message: self::translateWithLocale('notifications.templates.participant_added.message', [
                'actor' => $addedByName,
                'project' => $projectName,
                'role' => $roleLabel,
            ], $locale),
            data: [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'added_by_id' => $addedByUserId,
                'added_by_name' => $addedByName,
                'role' => $role,
            ]
        );
    }

    /**
     * Send participant removed notification
     */
    public static function notifyParticipantRemoved(
        int $removedUserId,
        int $removedByUserId,
        int $projectId,
        string $projectName
    ): Notification {
        $removedByUser = User::find($removedByUserId);
        $removedByName = $removedByUser?->name ?? 'Owner';

        return self::sendLocalizedNotification(
            userId: $removedUserId,
            type: Notification::TYPE_PARTICIPANT_REMOVED,
            titleKey: 'notifications.templates.participant_removed.title',
            messageKey: 'notifications.templates.participant_removed.message',
            replace: [
                'actor' => $removedByName,
                'project' => $projectName,
            ],
            data: [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'removed_by_id' => $removedByUserId,
                'removed_by_name' => $removedByName,
            ]
        );
    }

    /**
     * Send role changed notification
     */
    public static function notifyRoleChanged(
        int $userWhoseRoleChanged,
        int $changedByUserId,
        int $projectId,
        string $projectName,
        string $oldRole,
        string $newRole
    ): Notification {
        $changedByUser = User::find($changedByUserId);
        $changedByName = $changedByUser?->name ?? 'Owner';
        $locale = self::resolveLocaleForUser($userWhoseRoleChanged);

        return self::sendNotification(
            userId: $userWhoseRoleChanged,
            type: Notification::TYPE_ROLE_CHANGED,
            title: self::translateWithLocale('notifications.templates.role_changed.title', [], $locale),
            message: self::translateWithLocale('notifications.templates.role_changed.message', [
                'actor' => $changedByName,
                'project' => $projectName,
                'old_role' => self::translateRoleWithLocale($oldRole, $locale),
                'new_role' => self::translateRoleWithLocale($newRole, $locale),
            ], $locale),
            data: [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'changed_by_id' => $changedByUserId,
                'changed_by_name' => $changedByName,
                'old_role' => $oldRole,
                'new_role' => $newRole,
            ]
        );
    }

    /**
     * Broadcast notification to user via Reverb in real-time
     *
     * @param int $userId
     * @param Notification $notification
     */
    private static function broadcastNotification(int $userId, Notification $notification): void
    {
        try {
            // Try to broadcast via Reverb (if configured)
            // This will be handled by Laravel's broadcast system
            // We'll emit via private channel for the user
            broadcast(new \App\Events\NotificationCreated($notification));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast notification', [
                'error' => $e->getMessage(),
                'notification_id' => $notification->notification_id,
            ]);
        }
    }

    /**
     * Get unread count for user
     */
    public static function getUnreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Get notifications for user
     */
    public static function getNotifications(
        int $userId,
        int $perPage = 20,
        bool $onlyUnread = false
    ) {
        $query = Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($onlyUnread) {
            $query->where('is_read', false);
        }

        return $query->paginate($perPage);
    }

    /**
     * Mark all notifications as read for user
     */
    public static function markAllAsRead(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    private static function resolveLocaleForUser(int $userId): string
    {
        if (array_key_exists($userId, self::$localeCache)) {
            return self::$localeCache[$userId];
        }

        $language = trim(strtolower((string) User::query()
            ->where('user_id', $userId)
            ->value('language')));

        $locale = match ($language) {
            'rus', 'ru' => 'ru',
            'eng', 'en' => 'en',
            default => 'en',
        };

        self::$localeCache[$userId] = $locale;

        return $locale;
    }

    private static function translateWithLocale(string $key, array $replace, string $locale): string
    {
        $translated = trans($key, $replace, $locale);
        if (is_string($translated) && $translated !== '' && $translated !== $key) {
            return $translated;
        }

        $fallback = trans($key, $replace, 'en');
        if (is_string($fallback) && $fallback !== '' && $fallback !== $key) {
            return $fallback;
        }

        return $key;
    }

    private static function translateRoleWithLocale(string $role, string $locale): string
    {
        $normalized = trim(strtolower($role));
        if ($normalized === '') {
            return $role;
        }

        $roleKey = 'notifications.roles.'.$normalized;
        $translated = trans($roleKey, [], $locale);

        if (is_string($translated) && $translated !== '' && $translated !== $roleKey) {
            return $translated;
        }

        return $role;
    }
}

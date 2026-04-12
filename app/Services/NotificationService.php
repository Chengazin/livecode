<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
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

        return self::sendNotification(
            userId: $recipientUserId,
            type: Notification::TYPE_INVITATION_RECEIVED,
            title: 'Project Invitation',
            message: "{$senderName} invited you to join the project \"{$projectName}\"",
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

        return self::sendNotification(
            userId: $projectOwnerId,
            type: Notification::TYPE_INVITATION_ACCEPTED,
            title: 'Invitation Accepted',
            message: "{$userName} accepted your invitation to \"{$projectName}\"",
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

        return self::sendNotification(
            userId: $projectOwnerId,
            type: Notification::TYPE_INVITATION_DECLINED,
            title: 'Invitation Declined',
            message: "{$userName} declined your invitation to \"{$projectName}\"",
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

        return self::sendNotification(
            userId: $newParticipantUserId,
            type: Notification::TYPE_PARTICIPANT_ADDED,
            title: 'Added to Project',
            message: "{$addedByName} added you to \"{$projectName}\" as {$role}",
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

        return self::sendNotification(
            userId: $removedUserId,
            type: Notification::TYPE_PARTICIPANT_REMOVED,
            title: 'Removed from Project',
            message: "{$removedByName} removed you from \"{$projectName}\"",
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

        return self::sendNotification(
            userId: $userWhoseRoleChanged,
            type: Notification::TYPE_ROLE_CHANGED,
            title: 'Role Changed',
            message: "{$changedByName} changed your role in \"{$projectName}\" from {$oldRole} to {$newRole}",
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
}

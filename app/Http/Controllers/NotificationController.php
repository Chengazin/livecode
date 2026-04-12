<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Get user's notifications with pagination
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $perPage = $data['per_page'] ?? 20;
        $onlyUnread = $data['unread_only'] ?? false;

        $notifications = NotificationService::getNotifications(
            userId: $user->user_id,
            perPage: $perPage,
            onlyUnread: $onlyUnread
        );

        return response()->json([
            'data' => $notifications->items(),
            'pagination' => [
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
            ],
        ]);
    }

    /**
     * Get unread notifications count
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        $count = NotificationService::getUnreadCount($user->user_id);

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, int $notificationId)
    {
        $user = $request->user();

        $notification = Notification::query()
            ->where('notification_id', $notificationId)
            ->where('user_id', $user->user_id)
            ->firstOrFail();

        $notification->markAsRead();

        Log::info('Notification marked as read', [
            'notification_id' => $notificationId,
            'user_id' => $user->user_id,
        ]);

        return response()->json([
            'message' => 'Notification marked as read',
            'notification' => $notification,
        ]);
    }

    /**
     * Mark a notification as unread
     */
    public function markAsUnread(Request $request, int $notificationId)
    {
        $user = $request->user();

        $notification = Notification::query()
            ->where('notification_id', $notificationId)
            ->where('user_id', $user->user_id)
            ->firstOrFail();

        $notification->markAsUnread();

        Log::info('Notification marked as unread', [
            'notification_id' => $notificationId,
            'user_id' => $user->user_id,
        ]);

        return response()->json([
            'message' => 'Notification marked as unread',
            'notification' => $notification,
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $count = NotificationService::markAllAsRead($user->user_id);

        Log::info('All notifications marked as read', [
            'user_id' => $user->user_id,
            'count' => $count,
        ]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'marked_count' => $count,
        ]);
    }

    /**
     * Delete a notification
     */
    public function delete(Request $request, int $notificationId)
    {
        $user = $request->user();

        $notification = Notification::query()
            ->where('notification_id', $notificationId)
            ->where('user_id', $user->user_id)
            ->firstOrFail();

        $notification->delete();

        Log::info('Notification deleted', [
            'notification_id' => $notificationId,
            'user_id' => $user->user_id,
        ]);

        return response()->noContent();
    }

    /**
     * Delete all notifications
     */
    public function deleteAll(Request $request)
    {
        $user = $request->user();
        $count = Notification::query()
            ->where('user_id', $user->user_id)
            ->delete();

        Log::info('All notifications deleted', [
            'user_id' => $user->user_id,
            'count' => $count,
        ]);

        return response()->json([
            'message' => 'All notifications deleted',
            'deleted_count' => $count,
        ]);
    }
}


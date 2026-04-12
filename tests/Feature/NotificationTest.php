<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => bcrypt('password'),
            'status' => 'active',
        ]);

        $this->otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password_hash' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    /**
     * Test sending notification via service
     */
    public function test_send_notification()
    {
        $notification = NotificationService::sendNotification(
            userId: $this->user->user_id,
            type: Notification::TYPE_INVITATION_RECEIVED,
            title: 'Test Invitation',
            message: 'You have received an invitation',
            data: ['project_id' => 123, 'project_name' => 'Test Project']
        );

        $this->assertNotNull($notification->notification_id);
        $this->assertEquals($this->user->user_id, $notification->user_id);
        $this->assertEquals(Notification::TYPE_INVITATION_RECEIVED, $notification->type);
        $this->assertFalse($notification->is_read);
        $this->assertNull($notification->read_at);

        $this->assertDatabaseHas('notifications', [
            'notification_id' => $notification->notification_id,
            'user_id' => $this->user->user_id,
            'type' => Notification::TYPE_INVITATION_RECEIVED,
        ]);
    }

    /**
     * Test notify invitation received
     */
    public function test_notify_invitation_received()
    {
        $notification = NotificationService::notifyInvitationReceived(
            recipientUserId: $this->user->user_id,
            senderUserId: $this->otherUser->user_id,
            projectId: 1,
            projectName: 'My Project'
        );

        $this->assertEquals(Notification::TYPE_INVITATION_RECEIVED, $notification->type);
        $this->assertStringContainsString($this->otherUser->name, $notification->message);
        $this->assertStringContainsString('My Project', $notification->message);
        $this->assertEquals(1, $notification->data['project_id']);
    }

    /**
     * Test notify role changed
     */
    public function test_notify_role_changed()
    {
        $notification = NotificationService::notifyRoleChanged(
            userWhoseRoleChanged: $this->user->user_id,
            changedByUserId: $this->otherUser->user_id,
            projectId: 1,
            projectName: 'Project',
            oldRole: 'editor',
            newRole: 'maintainer'
        );

        $this->assertEquals(Notification::TYPE_ROLE_CHANGED, $notification->type);
        $this->assertStringContainsString('editor', $notification->message);
        $this->assertStringContainsString('maintainer', $notification->message);
        $this->assertEquals('editor', $notification->data['old_role']);
        $this->assertEquals('maintainer', $notification->data['new_role']);
    }

    /**
     * Test get unread count
     */
    public function test_get_unread_count()
    {
        // Create 3 unread notifications
        NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Invitation 1',
            'Test message 1'
        );
        NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Invitation 2',
            'Test message 2'
        );
        NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_PARTICIPANT_ADDED,
            'Added',
            'You were added'
        );

        $count = NotificationService::getUnreadCount($this->user->user_id);
        $this->assertEquals(3, $count);
    }

    /**
     * Test get notifications with pagination
     */
    public function test_get_notifications_pagination()
    {
        // Create 5 notifications
        for ($i = 0; $i < 5; $i++) {
            NotificationService::sendNotification(
                $this->user->user_id,
                Notification::TYPE_INVITATION_RECEIVED,
                "Invitation {$i}",
                "Test message {$i}"
            );
        }

        $notifications = NotificationService::getNotifications($this->user->user_id, perPage: 2);

        $this->assertEquals(5, $notifications->total());
        $this->assertEquals(2, $notifications->count());
        $this->assertEquals(3, $notifications->lastPage());
    }

    /**
     * Test mark notification as read
     */
    public function test_mark_notification_as_read()
    {
        $notification = NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Test',
            'Test'
        );

        $this->assertFalse($notification->is_read);
        $this->assertNull($notification->read_at);

        $notification->markAsRead();

        $notification->refresh();
        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    /**
     * Test mark all notifications as read
     */
    public function test_mark_all_as_read()
    {
        // Create 3 unread notifications
        for ($i = 0; $i < 3; $i++) {
            NotificationService::sendNotification(
                $this->user->user_id,
                Notification::TYPE_INVITATION_RECEIVED,
                "Notification {$i}",
                "Message {$i}"
            );
        }

        $unreadBefore = NotificationService::getUnreadCount($this->user->user_id);
        $this->assertEquals(3, $unreadBefore);

        $count = NotificationService::markAllAsRead($this->user->user_id);
        $this->assertEquals(3, $count);

        $unreadAfter = NotificationService::getUnreadCount($this->user->user_id);
        $this->assertEquals(0, $unreadAfter);
    }

    /**
     * Test API: Get notifications
     */
    public function test_api_get_notifications()
    {
        // Create 5 notifications
        for ($i = 0; $i < 5; $i++) {
            NotificationService::sendNotification(
                $this->user->user_id,
                Notification::TYPE_INVITATION_RECEIVED,
                "Notification {$i}",
                "Message {$i}"
            );
        }

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/notifications?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('pagination.total', 5);
        $response->assertJsonPath('pagination.last_page', 3);
    }

    /**
     * Test API: Get unread count
     */
    public function test_api_unread_count()
    {
        // Create 2 unread notifications
        NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Notification 1',
            'Message 1'
        );
        NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Notification 2',
            'Message 2'
        );

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/notifications/unread-count');

        $response->assertOk();
        $response->assertJson(['unread_count' => 2]);
    }

    /**
     * Test API: Mark as read
     */
    public function test_api_mark_as_read()
    {
        $notification = NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Test',
            'Test'
        );

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->patchJson(
            "/api/notifications/{$notification->notification_id}/mark-as-read"
        );

        $response->assertOk();
        $response->assertJson(['message' => 'Notification marked as read']);

        $notification->refresh();
        $this->assertTrue($notification->is_read);
    }

    /**
     * Test API: Delete notification
     */
    public function test_api_delete_notification()
    {
        $notification = NotificationService::sendNotification(
            $this->user->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Test',
            'Test'
        );

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->deleteJson(
            "/api/notifications/{$notification->notification_id}"
        );

        $response->assertNoContent();

        $this->assertDatabaseMissing('notifications', [
            'notification_id' => $notification->notification_id,
        ]);
    }

    /**
     * Test cannot access other user's notification
     */
    public function test_cannot_mark_other_user_notification()
    {
        $notification = NotificationService::sendNotification(
            $this->otherUser->user_id,
            Notification::TYPE_INVITATION_RECEIVED,
            'Test',
            'Test'
        );

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->patchJson(
            "/api/notifications/{$notification->notification_id}/mark-as-read"
        );

        $response->assertNotFound();
    }
}

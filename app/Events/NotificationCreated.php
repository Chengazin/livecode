<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->notification->user_id}.notifications"),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'notification_id' => $this->notification->notification_id,
                'user_id' => $this->notification->user_id,
                'type' => $this->notification->type,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'data' => $this->notification->data,
                'is_read' => $this->notification->is_read,
                'created_at' => $this->notification->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }
}

<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectFilesystemEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public int $projectId;
    public int $userId;
    public string $event;
    public string $path;

    public function __construct(int $projectId, int $userId, string $event, string $path)
    {
        $this->projectId = $projectId;
        $this->userId = $userId;
        $this->event = $event;
        $this->path = $path;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.'.$this->projectId)];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->event,
            'path' => $this->path,
            'user_id' => $this->userId,
        ];
    }
}

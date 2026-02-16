<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public int $projectId;
    public int $userId;
    public string $name;

    /**
     * @var array<string, mixed>
     */
    public array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(int $projectId, int $userId, string $name, array $payload = [])
    {
        $this->projectId = $projectId;
        $this->userId = $userId;
        $this->name = $name;
        $this->payload = $payload;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.'.$this->projectId)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return array_merge($this->payload, [
            'event' => $this->name,
            'user_id' => $this->userId,
            'project_id' => $this->projectId,
        ]);
    }
}

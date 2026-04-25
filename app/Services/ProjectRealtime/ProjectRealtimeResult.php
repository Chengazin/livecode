<?php

namespace App\Services\ProjectRealtime;

class ProjectRealtimeResult
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $eventPayload
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly ?string $eventName = null,
        public readonly array $eventPayload = []
    ) {
    }
}

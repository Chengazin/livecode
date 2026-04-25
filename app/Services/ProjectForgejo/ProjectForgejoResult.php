<?php

namespace App\Services\ProjectForgejo;

class ProjectForgejoResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $status,
        public readonly array $payload
    ) {
    }
}

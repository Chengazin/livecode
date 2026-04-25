<?php

namespace App\Services\ProjectTask;

class ProjectTaskWorkflowResult
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

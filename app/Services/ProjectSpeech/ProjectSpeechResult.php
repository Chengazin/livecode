<?php

namespace App\Services\ProjectSpeech;

class ProjectSpeechResult
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

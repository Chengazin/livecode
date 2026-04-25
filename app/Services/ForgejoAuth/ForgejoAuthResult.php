<?php

namespace App\Services\ForgejoAuth;

class ForgejoAuthResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
        public readonly ?string $stateBinding = null
    ) {
    }
}

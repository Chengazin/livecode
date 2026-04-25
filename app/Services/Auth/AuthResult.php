<?php

namespace App\Services\Auth;

class AuthResult
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

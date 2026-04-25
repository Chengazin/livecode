<?php

namespace App\Support\ProjectTerminal;

final class ProjectTerminalValidation
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function createSessionRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'cwd' => ['nullable', 'string', 'max:2048'],
            'shared' => ['nullable', 'boolean'],
            'shell' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function gatewayCloseRules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:closed'],
            'reason' => ['nullable', 'string', 'max:120'],
            'runtime' => ['nullable', 'string', 'max:32'],
            'exit_code' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'signal' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'closed_at' => ['nullable', 'date'],
            'request_user_id' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

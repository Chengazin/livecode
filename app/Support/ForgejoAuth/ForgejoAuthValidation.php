<?php

namespace App\Support\ForgejoAuth;

final class ForgejoAuthValidation
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function startRules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:login,connect'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function callbackRules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ];
    }
}

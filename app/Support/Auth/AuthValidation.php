<?php

namespace App\Support\Auth;

final class AuthValidation
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function registerInitiateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'language' => ['sometimes', 'string', 'in:rus,eng'],
            'captcha_token' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function registerVerifyRules(): array
    {
        return [
            'registration_verification_id' => ['required', 'integer'],
            'verification_code' => ['required', 'string', 'size:6'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function registerResendRules(): array
    {
        return [
            'registration_verification_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function loginRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}

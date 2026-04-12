<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Captcha Service Provider
    |--------------------------------------------------------------------------
    |
    | Configure which captcha service to use.
    |
    | Supported: "cloudflare-turnstile", "recaptcha", "disabled"
    |
    */

    'provider' => env('CAPTCHA_PROVIDER', 'disabled'),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile Configuration
    |--------------------------------------------------------------------------
    |
    | Cloudflare Turnstile is a more user-friendly alternative to reCAPTCHA.
    | It's free, easy to set up, and works great with any hosting provider.
    |
    | Get your keys at: https://dash.cloudflare.com/?to=/:account/turnstile
    |
    */

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'enabled' => env('TURNSTILE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA v3 Configuration
    |--------------------------------------------------------------------------
    |
    | reCAPTCHA v3 is Google's captcha service. It's free but requires account.
    | reCAPTCHA works silently in the background without user interaction.
    |
    | Get your keys at: https://www.google.com/recaptcha/admin
    |
    */

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'enabled' => env('RECAPTCHA_ENABLED', false),
        'threshold' => env('RECAPTCHA_THRESHOLD', 0.5),  // For v3, score threshold
    ],

    /*
    |--------------------------------------------------------------------------
    | Captcha Rules
    |--------------------------------------------------------------------------
    |
    | Configure when captcha verification is required.
    |
    */

    'verify_on' => [
        'registration_initiate' => true,   // Verify captcha on registration initiate
        'login' => false,                  // Verify captcha on login (optional)
    ],

];

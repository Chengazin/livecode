<?php

$turnstileEnabled = filter_var((string) env('TURNSTILE_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
$recaptchaEnabled = filter_var((string) env('RECAPTCHA_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
$yandexSmartCaptchaEnabled = filter_var((string) env('YANDEX_SMARTCAPTCHA_ENABLED', 'false'), FILTER_VALIDATE_BOOL);

$captchaProvider = (string) env('CAPTCHA_PROVIDER', '');
if ($captchaProvider === '') {
    $captchaProvider = $yandexSmartCaptchaEnabled
        ? 'yandex-smartcaptcha'
        : ($turnstileEnabled
            ? 'cloudflare-turnstile'
            : ($recaptchaEnabled ? 'recaptcha' : 'disabled'));
}

return [

    /*
    |--------------------------------------------------------------------------
    | Captcha Service Provider
    |--------------------------------------------------------------------------
    |
    | Configure which captcha service to use.
    |
    | Supported: "yandex-smartcaptcha", "cloudflare-turnstile", "recaptcha", "disabled"
    |
    */

    'provider' => $captchaProvider,

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
        'enabled' => $turnstileEnabled,
    ],

    /*
    |--------------------------------------------------------------------------
    | Yandex SmartCaptcha Configuration
    |--------------------------------------------------------------------------
    |
    | Yandex SmartCaptcha works well with Russian traffic and local hosting.
    | Get keys in Yandex Cloud console.
    |
    */

    'yandex_smartcaptcha' => [
        'site_key' => env('YANDEX_SMARTCAPTCHA_SITE_KEY'),
        'secret_key' => env('YANDEX_SMARTCAPTCHA_SECRET_KEY'),
        'enabled' => $yandexSmartCaptchaEnabled,
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
        'enabled' => $recaptchaEnabled,
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

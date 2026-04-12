<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CaptchaService
{
    /**
     * verify a captcha token
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        $provider = config('captcha.provider');

        if (!self::isEnabled()) {
            return true;
        }

        $token = trim($token);
        if ($token === '') {
            Log::warning('Captcha verification failed: empty token', [
                'provider' => $provider,
            ]);
            return false;
        }

        return match ($provider) {
            'yandex-smartcaptcha' => self::verifyYandexSmartCaptcha($token, $remoteIp),
            'cloudflare-turnstile' => self::verifyTurnstile($token, $remoteIp),
            'recaptcha' => self::verifyRecaptcha($token, $remoteIp),
            default => false,
        };
    }

    /**
     * Verify Yandex SmartCaptcha token
     */
    private static function verifyYandexSmartCaptcha(string $token, ?string $remoteIp = null): bool
    {
        if (!config('captcha.yandex_smartcaptcha.enabled')) {
            return true;
        }

        $secretKey = config('captcha.yandex_smartcaptcha.secret_key');

        if (!$secretKey || !$token) {
            Log::warning('Yandex SmartCaptcha verification failed: missing configuration or token');
            return false;
        }

        try {
            $query = [
                'secret' => $secretKey,
                'token' => $token,
            ];

            if ($remoteIp) {
                $query['ip'] = $remoteIp;
            }

            $response = Http::timeout(10)->get(
                'https://smartcaptcha.yandexcloud.net/validate',
                $query
            );

            if (!$response->successful()) {
                Log::warning('Yandex SmartCaptcha API returned error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return false;
            }

            $data = $response->json();
            if (($data['status'] ?? '') !== 'ok') {
                Log::info('Yandex SmartCaptcha verification failed', [
                    'status' => $data['status'] ?? 'unknown',
                    'message' => $data['message'] ?? null,
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Yandex SmartCaptcha verification exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify Cloudflare Turnstile token
     */
    private static function verifyTurnstile(string $token, ?string $remoteIp = null): bool
    {
        if (!config('captcha.turnstile.enabled')) {
            return true;
        }

        $secretKey = config('captcha.turnstile.secret_key');

        if (!$secretKey || !$token) {
            Log::warning('Turnstile verification failed: missing configuration or token');
            return false;
        }

        try {
            $response = Http::timeout(10)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]
            );

            if (!$response->successful()) {
                Log::warning('Turnstile API returned error', [
                    'status' => $response->status(),
                ]);
                return false;
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                Log::info('Turnstile verification failed', [
                    'error_codes' => $data['error-codes'] ?? [],
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Turnstile verification exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify Google reCAPTCHA v3 token
     */
    private static function verifyRecaptcha(string $token, ?string $remoteIp = null): bool
    {
        if (!config('captcha.recaptcha.enabled')) {
            return true;
        }

        $secretKey = config('captcha.recaptcha.secret_key');
        $threshold = config('captcha.recaptcha.threshold', 0.5);

        if (!$secretKey || !$token) {
            Log::warning('reCAPTCHA verification failed: missing configuration or token');
            return false;
        }

        try {
            $response = Http::timeout(10)->post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]
            );

            if (!$response->successful()) {
                Log::warning('reCAPTCHA API returned error', [
                    'status' => $response->status(),
                ]);
                return false;
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                Log::info('reCAPTCHA verification failed', [
                    'error_codes' => $data['error-codes'] ?? [],
                ]);
                return false;
            }

            // For v3, check score threshold
            $score = $data['score'] ?? 0;
            if ($score < $threshold) {
                Log::info('reCAPTCHA score below threshold', [
                    'score' => $score,
                    'threshold' => $threshold,
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get the public site key for the configured provider
     */
    public static function getSiteKey(): ?string
    {
        $provider = config('captcha.provider');

        return match ($provider) {
            'yandex-smartcaptcha' => config('captcha.yandex_smartcaptcha.site_key'),
            'cloudflare-turnstile' => config('captcha.turnstile.site_key'),
            'recaptcha' => config('captcha.recaptcha.site_key'),
            default => null,
        };
    }

    /**
     * Check if captcha is enabled
     */
    public static function isEnabled(): bool
    {
        $provider = config('captcha.provider');

        return match ($provider) {
            'yandex-smartcaptcha' => (bool) config('captcha.yandex_smartcaptcha.enabled')
                && trim((string) config('captcha.yandex_smartcaptcha.site_key', '')) !== ''
                && trim((string) config('captcha.yandex_smartcaptcha.secret_key', '')) !== '',
            'cloudflare-turnstile' => (bool) config('captcha.turnstile.enabled')
                && trim((string) config('captcha.turnstile.site_key', '')) !== ''
                && trim((string) config('captcha.turnstile.secret_key', '')) !== '',
            'recaptcha' => (bool) config('captcha.recaptcha.enabled')
                && trim((string) config('captcha.recaptcha.site_key', '')) !== ''
                && trim((string) config('captcha.recaptcha.secret_key', '')) !== '',
            default => false,
        };
    }
}

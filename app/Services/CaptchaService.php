<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
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

        if ($provider === 'disabled' || !$token) {
            return true;
        }

        return match ($provider) {
            'cloudflare-turnstile' => self::verifyTurnstile($token, $remoteIp),
            'recaptcha' => self::verifyRecaptcha($token, $remoteIp),
            default => true,
        };
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
        return config('captcha.provider') !== 'disabled';
    }
}

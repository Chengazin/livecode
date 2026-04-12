<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegistrationVerification extends Model
{
    protected $primaryKey = 'registration_verification_id';

    protected $fillable = [
        'email',
        'name',
        'password_hash',
        'language',
        'verification_code',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Generate a random verification code
     */
    public static function generateCode(): string
    {
        return Str::random(6, '0123456789');
    }

    /**
     * Create a new registration verification record
     */
    public static function createForRegistration(
        string $email,
        string $name,
        string $passwordHash,
        string $language = 'rus',
        int $expirationMinutes = 30
    ): self {
        return self::query()->create([
            'email' => $email,
            'name' => $name,
            'password_hash' => $passwordHash,
            'language' => $language,
            'verification_code' => self::generateCode(),
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => Carbon::now()->addMinutes($expirationMinutes),
        ]);
    }

    /**
     * Verify the code and mark as verified
     */
    public function verify(string $code): bool
    {
        // Check if already verified
        if ($this->verified_at !== null) {
            return false;
        }

        // Check if expired
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        // Check max attempts
        if ($this->attempts >= $this->max_attempts) {
            return false;
        }

        // Check code
        if ($this->verification_code !== $code) {
            $this->increment('attempts');
            return false;
        }

        // Mark as verified
        $this->verified_at = Carbon::now();
        $this->save();
        return true;
    }

    /**
     * Check if verification is still valid
     */
    public function isValid(): bool
    {
        return $this->verified_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->attempts < $this->max_attempts;
    }

    /**
     * Check if verification code is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}


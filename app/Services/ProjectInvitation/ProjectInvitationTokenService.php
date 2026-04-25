<?php

namespace App\Services\ProjectInvitation;

use App\Models\ProjectInvitation;
use Illuminate\Support\Str;

class ProjectInvitationTokenService
{
    public function generateInviteToken(): string
    {
        do {
            $token = Str::random(64);
        } while (ProjectInvitation::query()->where('invite_token', $token)->exists());

        return $token;
    }
}

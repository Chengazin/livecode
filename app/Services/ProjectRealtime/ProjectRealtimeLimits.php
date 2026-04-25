<?php

namespace App\Services\ProjectRealtime;

final class ProjectRealtimeLimits
{
    public const DEFAULT_AVATAR_PRESET = 'robot';

    public const PRESENCE_TTL_SECONDS = 20;

    public const CHAT_TTL_SECONDS = 43200;

    public const CHAT_MAX_MESSAGES = 200;

    public const EDITOR_STATE_TTL_SECONDS = 43200;

    public const EDITOR_MAX_CONTENT_LENGTH = 524288;

    public const EDITOR_MAX_INSERT_LENGTH = 524288;

    public const EDITOR_MAX_HISTORY = 500;

    public const EDITOR_LOCK_TTL_SECONDS = 5;
}

<?php

namespace App\Support\ProjectSpeech;

final class ProjectSpeechValidation
{
    private const MAX_AUDIO_SIZE_KB = 15360;

    /**
     * @return array<string, array<int, string>>
     */
    public static function transcribeRules(): array
    {
        return [
            'audio' => ['required', 'file', 'max:'.self::MAX_AUDIO_SIZE_KB],
            'language' => ['nullable', 'string', 'max:16'],
        ];
    }
}

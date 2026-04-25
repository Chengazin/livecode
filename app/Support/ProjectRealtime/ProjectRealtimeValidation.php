<?php

namespace App\Support\ProjectRealtime;

use App\Services\ProjectRealtime\ProjectRealtimeLimits;

final class ProjectRealtimeValidation
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function heartbeatRules(): array
    {
        return [
            'path' => ['nullable', 'string', 'max:2048'],
            'cursor_row' => ['nullable', 'integer', 'min:0'],
            'cursor_column' => ['nullable', 'integer', 'min:0'],
            'selection_start_row' => ['nullable', 'integer', 'min:0'],
            'selection_start_column' => ['nullable', 'integer', 'min:0'],
            'selection_end_row' => ['nullable', 'integer', 'min:0'],
            'selection_end_column' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function chatIndexRules(): array
    {
        return [
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function chatStoreRules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function editorSyncRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
            'client_id' => ['required', 'string', 'min:3', 'max:128'],
            'op_id' => ['required', 'string', 'min:3', 'max:128'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'delete_count' => ['required', 'integer', 'min:0'],
            'insert_text' => ['nullable', 'string', 'max:'.ProjectRealtimeLimits::EDITOR_MAX_INSERT_LENGTH],
            'cursor_row' => ['nullable', 'integer', 'min:0'],
            'cursor_column' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function editorStateRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
            'seed_content' => ['nullable', 'string', 'max:'.ProjectRealtimeLimits::EDITOR_MAX_CONTENT_LENGTH],
            'reset' => ['nullable', 'boolean'],
        ];
    }
}

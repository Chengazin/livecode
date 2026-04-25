<?php

namespace App\Support\ProjectTask;

use App\Models\ProjectTask;
use Illuminate\Validation\Rule;

final class ProjectTaskValidation
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function indexRules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in([
                ProjectTask::STATUS_BACKLOG,
                ProjectTask::STATUS_IN_PROGRESS,
                ProjectTask::STATUS_DONE,
            ])],
            'assigned_to_user_id' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', Rule::in(['priority', 'due_date', 'created'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function storeRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'integer', 'in:0,1,2,3'],
            'due_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'integer', 'in:0,1,2,3'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in([
                ProjectTask::STATUS_BACKLOG,
                ProjectTask::STATUS_IN_PROGRESS,
                ProjectTask::STATUS_DONE,
            ])],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function assignRules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
        ];
    }
}

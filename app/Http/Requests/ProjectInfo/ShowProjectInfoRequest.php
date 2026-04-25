<?php

namespace App\Http\Requests\ProjectInfo;

use Illuminate\Foundation\Http\FormRequest;

class ShowProjectInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'commit_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function periodDays(): int
    {
        return (int) ($this->validated('period_days') ?? 30);
    }

    public function commitLimit(): int
    {
        return (int) ($this->validated('commit_limit') ?? 200);
    }
}

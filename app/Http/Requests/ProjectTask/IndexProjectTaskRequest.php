<?php

namespace App\Http\Requests\ProjectTask;

use App\Support\ProjectTask\ProjectTaskValidation;
use Illuminate\Foundation\Http\FormRequest;

class IndexProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ProjectTaskValidation::indexRules();
    }

    public function status(): ?string
    {
        $value = $this->validated('status');

        return is_string($value) ? $value : null;
    }

    public function assignedToUserId(): ?int
    {
        $value = $this->validated('assigned_to_user_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function sortBy(): string
    {
        $value = $this->validated('sort_by');

        return is_string($value) && $value !== '' ? $value : 'priority';
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page');

        return is_numeric($value) ? (int) $value : 15;
    }
}

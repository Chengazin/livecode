<?php

namespace App\Http\Requests\ProjectTask;

use App\Support\ProjectTask\ProjectTaskValidation;
use Illuminate\Foundation\Http\FormRequest;

class AssignProjectTaskRequest extends FormRequest
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
        return ProjectTaskValidation::assignRules();
    }

    public function userId(): int
    {
        return (int) $this->validated('user_id');
    }
}

<?php

namespace App\Http\Requests\ProjectTask;

use App\Support\ProjectTask\ProjectTaskValidation;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectTaskRequest extends FormRequest
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
        return ProjectTaskValidation::storeRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}

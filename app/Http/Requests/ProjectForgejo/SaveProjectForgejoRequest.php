<?php

namespace App\Http\Requests\ProjectForgejo;

use Illuminate\Foundation\Http\FormRequest;

class SaveProjectForgejoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function message(): ?string
    {
        $value = $this->validated('message');

        return is_string($value) ? $value : null;
    }
}

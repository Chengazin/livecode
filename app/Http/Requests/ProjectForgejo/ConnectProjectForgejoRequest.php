<?php

namespace App\Http\Requests\ProjectForgejo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectProjectForgejoRequest extends FormRequest
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
            'mode' => ['required', 'string', Rule::in(['create', 'existing'])],
            'repo_name' => ['nullable', 'string', 'max:255'],
            'private' => ['sometimes', 'boolean'],
            'repo_url' => ['required_if:mode,existing', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}

<?php

namespace App\Http\Requests\ProjectForgejo;

use Illuminate\Foundation\Http\FormRequest;

class PullRequestProjectForgejoRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'message' => ['nullable', 'string', 'max:255'],
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

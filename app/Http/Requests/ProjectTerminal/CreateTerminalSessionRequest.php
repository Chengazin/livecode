<?php

namespace App\Http\Requests\ProjectTerminal;

use App\Support\ProjectTerminal\ProjectTerminalValidation;
use Illuminate\Foundation\Http\FormRequest;

class CreateTerminalSessionRequest extends FormRequest
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
        return ProjectTerminalValidation::createSessionRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}

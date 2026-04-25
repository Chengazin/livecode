<?php

namespace App\Http\Requests\ForgejoAuth;

use App\Support\ForgejoAuth\ForgejoAuthValidation;
use Illuminate\Foundation\Http\FormRequest;

class StartForgejoAuthRequest extends FormRequest
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
        return ForgejoAuthValidation::startRules();
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}

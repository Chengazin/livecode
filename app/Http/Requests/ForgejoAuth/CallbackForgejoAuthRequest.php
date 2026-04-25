<?php

namespace App\Http\Requests\ForgejoAuth;

use App\Support\ForgejoAuth\ForgejoAuthValidation;
use Illuminate\Foundation\Http\FormRequest;

class CallbackForgejoAuthRequest extends FormRequest
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
        return ForgejoAuthValidation::callbackRules();
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    public function state(): string
    {
        return (string) $this->validated('state');
    }
}

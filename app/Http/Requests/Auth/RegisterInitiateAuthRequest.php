<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\AuthValidation;
use Illuminate\Foundation\Http\FormRequest;

class RegisterInitiateAuthRequest extends FormRequest
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
        return AuthValidation::registerInitiateRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}

<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\AuthValidation;
use Illuminate\Foundation\Http\FormRequest;

class LoginAuthRequest extends FormRequest
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
        return AuthValidation::loginRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}

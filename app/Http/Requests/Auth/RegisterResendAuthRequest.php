<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\AuthValidation;
use Illuminate\Foundation\Http\FormRequest;

class RegisterResendAuthRequest extends FormRequest
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
        return AuthValidation::registerResendRules();
    }

    public function verificationId(): int
    {
        return (int) $this->validated('registration_verification_id');
    }
}

<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'A confirmação da senha não confere.',
            'password.different' => 'A nova senha precisa ser diferente da atual.',
        ];
    }
}

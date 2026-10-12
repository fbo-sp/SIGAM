<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class SessionVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['registration_number' => mb_strtoupper(trim((string) $this->input('registration_number')))]);
    }

    public function rules(): array
    {
        return ['registration_number' => ['required', 'string', 'max:80']];
    }
}

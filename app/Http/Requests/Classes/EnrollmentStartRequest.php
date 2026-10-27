<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class EnrollmentStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['corrected_start' => ['required', 'date_format:Y-m-d'], 'correction_reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    public function attributes(): array
    {
        return ['corrected_start' => 'início correto', 'correction_reason' => 'motivo'];
    }
}

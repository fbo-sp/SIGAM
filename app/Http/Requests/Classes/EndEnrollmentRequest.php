<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class EndEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['end_date' => ['required', 'date_format:Y-m-d'], 'exit_reason' => ['nullable', 'string', 'max:500']];
    }
}

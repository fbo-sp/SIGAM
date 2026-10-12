<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class EnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['student_id' => ['required', 'integer', 'exists:students,id'], 'start_date' => ['required', 'date']];
    }
}

<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['records' => ['required', 'array', 'min:1'], 'records.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'], 'records.*.status' => ['required', Rule::in(['present', 'absent', 'excused_absence', 'makeup', 'trial'])], 'records.*.notes' => ['nullable', 'string', 'max:1000'], 'close_attendance' => ['nullable', 'boolean']];
    }
}

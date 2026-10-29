<?php

namespace App\Http\Requests\Attendance;

use App\Services\Attendance\AttendanceService;
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
        return ['records' => ['required', 'array', 'min:1'], 'records.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'], 'records.*.status' => ['nullable', Rule::in(AttendanceService::RECORDABLE_STATUSES)], 'records.*.notes' => ['nullable', 'string', 'max:1000'], 'close_attendance' => ['nullable', 'boolean'], 'staff_lead' => ['nullable', 'integer'], 'staff_assistants' => ['nullable', 'array'], 'staff_assistants.*' => ['integer']];
    }
}

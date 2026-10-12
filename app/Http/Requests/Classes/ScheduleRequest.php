<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['weekday' => ['required', 'integer', 'between:1,7'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'], 'teacher_id' => ['nullable', 'integer', 'exists:teacher_profiles,id'], 'location_name' => ['nullable', 'string', 'max:255'], 'valid_from' => ['required', 'date']];
    }
}

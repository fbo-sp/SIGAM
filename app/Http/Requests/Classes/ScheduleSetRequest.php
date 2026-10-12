<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $schedules = collect((array) $this->input('schedules'))->filter(fn ($row) => filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL))->map(fn ($row, $weekday) => [
            'weekday' => (int) ($row['weekday'] ?? $weekday),
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'teacher_id' => $row['teacher_id'] ?? null,
            'location_name' => $row['location_name'] ?? null,
        ])->values()->all();
        $this->merge(['schedules' => $schedules]);
    }

    public function rules(): array
    {
        return [
            'valid_from' => ['required', 'date', 'after:today'],
            'schedules' => ['required', 'array', 'min:1', 'max:7'],
            'schedules.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
            'schedules.*.teacher_id' => ['nullable', 'integer', 'exists:teacher_profiles,id'],
            'schedules.*.location_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}

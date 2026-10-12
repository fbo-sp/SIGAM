<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $association = $this->route('association');

        return ['dojo_id' => ['required', 'integer', 'exists:dojos,id'], 'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'], 'name' => ['required', 'string', 'max:255', Rule::unique('class_groups')->where(fn ($query) => $query->where('association_id', $association->id)->where('dojo_id', $this->dojo_id))], 'description' => ['nullable', 'string', 'max:5000'], 'main_teacher_id' => ['nullable', 'integer', 'exists:teacher_profiles,id'], 'age_group' => ['nullable', 'string', 'max:80'], 'minimum_age' => ['nullable', 'integer', 'min:0', 'max:120'], 'maximum_age' => ['nullable', 'integer', 'gte:minimum_age', 'max:120'], 'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'], 'future_session_target' => ['required', 'integer', Rule::in([4, 8])], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'schedule_mode' => ['nullable', Rule::in(['same', 'different'])], 'schedules' => ['required', 'array', 'min:1', 'max:7'], 'schedules.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'], 'schedules.*.start_time' => ['required', 'date_format:H:i'], 'schedules.*.end_time' => ['required', 'date_format:H:i'], 'schedules.*.teacher_id' => ['nullable', 'integer', 'exists:teacher_profiles,id'], 'schedules.*.location_name' => ['nullable', 'string', 'max:255']];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('future_session_target')) {
            $this->merge(['future_session_target' => 8]);
        }

        if ($this->has('schedules')) {
            $inputSchedules = collect((array) $this->input('schedules'));
            $usesEnabledFlags = $inputSchedules->contains(fn ($row) => array_key_exists('enabled', (array) $row));
            $schedules = $inputSchedules->filter(fn ($row) => ! $usesEnabledFlags || filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL))->map(fn ($row, $weekday) => [
                'weekday' => (int) ($row['weekday'] ?? $weekday),
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'teacher_id' => $row['teacher_id'] ?? null,
                'location_name' => $row['location_name'] ?? null,
            ])->values()->all();
            $this->merge(['schedules' => $schedules]);

            return;
        }

        $weekdays = (array) $this->input('weekdays', []);
        if ($weekdays === [] && $this->filled('weekday')) {
            $weekdays = [$this->input('weekday')];
        }
        $this->merge(['schedules' => collect($weekdays)->map(fn ($weekday) => [
            'weekday' => (int) $weekday,
            'start_time' => $this->input('start_time'),
            'end_time' => $this->input('end_time'),
            'teacher_id' => $this->input('main_teacher_id'),
            'location_name' => $this->input('location_name'),
        ])->values()->all()]);
    }
}

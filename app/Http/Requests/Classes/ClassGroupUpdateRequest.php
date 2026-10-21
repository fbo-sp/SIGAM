<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassGroupUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = $this->route('classGroup');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('class_groups')->ignore($group->id)
                ->where(fn ($query) => $query->where('association_id', $group->association_id)->where('dojo_id', $group->dojo_id))],
            'description' => ['nullable', 'string', 'max:5000'],
            'main_teacher_id' => ['nullable', 'integer', 'exists:teacher_profiles,id'],
            'age_group' => ['nullable', 'string', 'max:80'],
            'minimum_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'maximum_age' => ['nullable', 'integer', 'gte:minimum_age', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'future_session_target' => ['required', 'integer', Rule::in([4, 8])],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}

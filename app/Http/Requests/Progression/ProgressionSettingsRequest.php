<?php

namespace App\Http\Requests\Progression;

use Illuminate\Foundation\Http\FormRequest;

class ProgressionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quiz_question_count' => ['required', 'integer', 'min:1', 'max:50'],
            'quiz_minimum_score' => ['required', 'integer', 'min:1', 'max:100'],
            'assessment_reminder_weeks' => ['required', 'integer', 'min:1', 'max:52'],
            'allow_group_pick' => ['boolean'],
        ];
    }
}

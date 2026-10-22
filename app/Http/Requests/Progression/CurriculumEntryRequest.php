<?php

namespace App\Http\Requests\Progression;

use App\Support\Progression\AssessmentLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurriculumEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 0 significa "deixar de cobrar a partir desta faixa".
        return [
            'technique_item_id' => ['required', 'integer'],
            'required_level' => ['required', 'integer', Rule::in([0, ...array_keys(AssessmentLevel::requirable())])],
        ];
    }

    public function level(): ?int
    {
        return (int) $this->validated('required_level') ?: null;
    }
}

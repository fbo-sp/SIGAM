<?php

namespace App\Http\Requests\Progression;

use Illuminate\Foundation\Http\FormRequest;

class QuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'statement' => trim((string) $this->statement),
            'explanation' => trim((string) $this->explanation) ?: null,
            'technique_item_id' => $this->technique_item_id ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'rank_id' => ['required', 'integer'],
            'technique_item_id' => ['nullable', 'integer'],
            'statement' => ['required', 'string', 'max:2000'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'options' => ['required', 'array', 'min:2', 'max:5'],
            'options.*' => ['nullable', 'string', 'max:500'],
            'correct' => ['required', 'integer', 'min:0', 'max:4'],
        ];
    }

    public function messages(): array
    {
        return ['correct.required' => 'Marque a alternativa correta.'];
    }
}

<?php

namespace App\Http\Requests\Exams;

use App\Models\RankExam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Dados do exame: nome, modalidade, nível, período, média mínima, banca e prova teórica (quiz). */
class ExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'minimum_average' => str_replace(',', '.', trim((string) $this->input('minimum_average'))),
            'class_teachers_examine' => $this->boolean('class_teachers_examine'),
            'quiz_enabled' => $this->boolean('quiz_enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'martial_art_id' => ['required', 'integer'],
            'scope_type' => ['required', Rule::in(array_keys(RankExam::SCOPES))],
            'dojo_id' => ['nullable', 'integer'],
            'class_group_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'justification' => ['nullable', 'required_if:scope_type,student', 'string', 'min:5', 'max:1000'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'minimum_average' => ['required', 'numeric', 'between:0,10', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'class_teachers_examine' => ['boolean'],
            'examiner_ids' => ['array'],
            'examiner_ids.*' => ['integer'],
            'quiz_enabled' => ['boolean'],
            'quiz_question_count' => ['nullable', 'required_if:quiz_enabled,true', 'integer', 'between:1,50'],
            'quiz_time_limit_minutes' => ['nullable', 'integer', 'between:1,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'justification.required_if' => 'Exame de um aluno só precisa de justificativa (ex.: voltou de lesão).',
            'ends_on.after_or_equal' => 'O fim precisa ser no mesmo dia ou depois do início.',
            'minimum_average.between' => 'A média mínima vai de 0 a 10.',
            'minimum_average.regex' => 'Use até duas casas decimais, como 7,0 ou 7,5.',
            'quiz_question_count.required_if' => 'Informe quantas perguntas o quiz terá.',
        ];
    }
}

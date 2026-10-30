<?php

namespace App\Http\Requests\Sports;

use Illuminate\Foundation\Http\FormRequest;

/** Encerrar vínculo de responsável: grava a data de fim, nunca apaga. */
class GuardianEndRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['end_date' => $this->input('end_date', today()->toDateString())]);
    }

    public function rules(): array
    {
        return [
            'guardian_id' => ['nullable', 'integer'],
            'end_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

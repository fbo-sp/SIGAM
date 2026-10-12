<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->name), 'amount' => str_replace(',', '.', (string) $this->amount)]);
    }

    public function rules(): array
    {
        return ['dojo_id' => ['nullable', 'integer', 'exists:dojos,id'], 'martial_art_id' => ['nullable', 'integer', 'exists:martial_arts,id'], 'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:5000'], 'billing_period' => ['required', Rule::in(['monthly', 'semiannual', 'annual', 'custom'])], 'amount' => ['required', 'decimal:0,2', 'min:0'], 'valid_from' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from']];
    }
}

<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => str_replace(',', '.', trim((string) $this->input('amount'))),
            'reference' => $this->filled('reference') ? trim((string) $this->input('reference')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'payment_method' => ['required', Rule::in(['pix', 'cash', 'card', 'transfer', 'boleto', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

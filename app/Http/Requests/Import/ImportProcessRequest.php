<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ImportProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['confirm_reviews' => $this->boolean('confirm_reviews')]);
    }

    public function rules(): array
    {
        return ['confirm_reviews' => ['boolean'], 'acoes' => ['nullable', 'array', 'max:5000'], 'acoes.*' => ['string', 'in:ignore,update,new']];
    }
}

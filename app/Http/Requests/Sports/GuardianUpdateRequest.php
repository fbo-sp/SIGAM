<?php

namespace App\Http\Requests\Sports;

use App\Services\Sports\GuardianService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Editar parentesco e marcações de um vínculo vigente. */
class GuardianUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(['is_legal_guardian', 'is_financial_guardian', 'is_primary', 'can_receive_notifications'])
            ->mapWithKeys(fn (string $flag) => [$flag => $this->boolean($flag)])->all());
    }

    public function rules(): array
    {
        return [
            'guardian_id' => ['nullable', 'integer'],
            'relationship_type' => ['required', Rule::in(array_keys(GuardianService::RELATIONSHIPS))],
            'is_legal_guardian' => ['boolean'], 'is_financial_guardian' => ['boolean'], 'is_primary' => ['boolean'], 'can_receive_notifications' => ['boolean'],
        ];
    }
}

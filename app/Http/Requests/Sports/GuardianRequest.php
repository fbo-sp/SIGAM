<?php

namespace App\Http\Requests\Sports;

use App\Rules\Cpf;
use App\Services\Sports\GuardianService;
use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adicionar responsável: pessoa nova (nome, CPF, celular e e-mail) ou já cadastrada. A permissão fica no GuardianService. */
class GuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mode' => $this->input('mode', $this->filled('guardian_person_id') ? 'existing' : 'new'),
            'full_name' => is_string($this->input('full_name')) ? trim(preg_replace('/\s+/u', ' ', $this->input('full_name'))) : $this->input('full_name'),
            'cpf' => InputNormalizer::digits($this->input('cpf')),
            'mobile' => InputNormalizer::digits($this->input('mobile')),
            'email' => InputNormalizer::email($this->input('email')),
            'start_date' => $this->input('start_date', today()->toDateString()),
            ...collect(['is_legal_guardian', 'is_financial_guardian', 'is_primary', 'can_receive_notifications', 'release_portal'])
                ->mapWithKeys(fn (string $flag) => [$flag => $this->boolean($flag)])->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['new', 'existing'])],
            'guardian_person_id' => ['nullable', 'required_if:mode,existing', 'integer'],
            'full_name' => ['nullable', 'required_if:mode,new', 'string', 'min:3', 'max:255'],
            'cpf' => ['nullable', new Cpf],
            'mobile' => ['nullable', 'string', 'between:10,11'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'relationship_type' => ['required', Rule::in(array_keys(GuardianService::RELATIONSHIPS))],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_legal_guardian' => ['boolean'], 'is_financial_guardian' => ['boolean'], 'is_primary' => ['boolean'],
            'can_receive_notifications' => ['boolean'], 'release_portal' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required_if' => 'Informe o nome completo do responsável.',
            'guardian_person_id.required_if' => 'Escolha a pessoa já cadastrada.',
            'start_date.before_or_equal' => 'O vínculo não pode começar depois de hoje.',
        ];
    }
}

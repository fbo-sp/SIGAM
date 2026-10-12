<?php

namespace App\Http\Requests\Access;

use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person_id' => ['required', 'integer'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(AssociationRoleCatalog::codes())],
        ];
    }

    public function messages(): array
    {
        return [
            'person_id.required' => 'Escolha a pessoa que vai usar a conta.',
            'roles.required' => 'Escolha pelo menos um perfil.',
        ];
    }
}

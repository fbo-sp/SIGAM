<?php

namespace App\Http\Requests\Access;

use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in(AssociationRoleCatalog::codes())],
        ];
    }
}

<?php

namespace App\Http\Requests\People;

use Illuminate\Support\Arr;

/** Dados pessoais que o gestor do dojo pode corrigir. CPF, documento, situação e observações continuam com a associação. */
class DojoStudentPersonRequest extends PersonRequest
{
    public const FIELDS = [
        'full_name', 'social_name', 'birth_date', 'email', 'phone', 'mobile', 'postal_code', 'street', 'number',
        'complement', 'district', 'city', 'state', 'country', 'emergency_contact_name', 'emergency_contact_phone',
    ];

    public function rules(): array
    {
        return Arr::only(parent::rules(), self::FIELDS);
    }
}

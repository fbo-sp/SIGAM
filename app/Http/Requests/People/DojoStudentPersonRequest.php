<?php

namespace App\Http\Requests\People;

use Illuminate\Support\Arr;

/** Dados pessoais (e foto) que o gestor do dojo pode corrigir. CPF, documento, situação e observações continuam com a associação. */
class DojoStudentPersonRequest extends PersonRequest
{
    public const FIELDS = [
        'full_name', 'social_name', 'birth_date', 'email', 'phone', 'mobile', 'postal_code', 'street', 'number',
        'complement', 'district', 'city', 'state', 'country', 'emergency_contact_name', 'emergency_contact_phone', 'photo', 'remove_photo',
    ];

    public function rules(): array
    {
        return Arr::only(parent::rules(), self::FIELDS);
    }
}

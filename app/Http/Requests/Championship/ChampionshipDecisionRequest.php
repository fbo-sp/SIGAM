<?php

namespace App\Http\Requests\Championship;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChampionshipDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approve', 'reject'])], 'decision_reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:10', 'max:5000']];
    }
}

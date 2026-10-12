<?php

namespace App\Http\Requests\Auth;

use App\Services\Access\TeacherInvitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AcceptTeacherInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $invitation = app(TeacherInvitationService::class)->findByToken((string) $this->route('token'));
        $usable = $invitation !== null
            && in_array($invitation->status, ['pending', 'sent'], true)
            && $invitation->revoked_at === null
            && ! $invitation->expires_at->isPast();

        if (! $usable) {
            return ['accept_terms' => ['accepted']];
        }

        $existing = $invitation?->user?->status === 'active';

        return [
            'accept_terms' => ['accepted'],
            'current_password' => [Rule::requiredIf($existing), 'nullable', 'string'],
            'password' => [Rule::requiredIf(! $existing), 'nullable', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Confirme que deseja aceitar o convite.',
            'current_password.required' => 'Informe a senha atual da sua conta.',
            'password.required' => 'Crie uma senha para ativar sua conta.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ];
    }
}

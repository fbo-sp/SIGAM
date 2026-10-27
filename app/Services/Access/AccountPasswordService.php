<?php

namespace App\Services\Access;

use App\Mail\PasswordChangedMail;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Troca da própria senha pela conta logada: confere a senha atual, encerra as outras sessões
 * e o "lembrar de mim", audita e avisa por e-mail (falha de envio não desfaz a troca).
 */
class AccountPasswordService
{
    public function __construct(private readonly AuditService $audit) {}

    public function change(User $user, string $current, string $new, ?string $keepSessionId): void
    {
        if (! Hash::check($current, $user->password)) {
            $this->audit->record('security.password_change_failed', 'rejected', $user);

            throw ValidationException::withMessages(['current_password' => 'A senha atual não confere.']);
        }

        DB::transaction(function () use ($user, $new, $keepSessionId): void {
            $user->forceFill(['password' => Hash::make($new), 'remember_token' => Str::random(60)])->save();
            // Sessões ficam no banco (SESSION_DRIVER=database); as outras sessões da conta deixam de valer.
            DB::table('sessions')->where('user_id', $user->id)
                ->when($keepSessionId, fn ($query) => $query->where('id', '!=', $keepSessionId))->delete();
            $this->audit->record('security.password_changed', 'updated', $user);
        });

        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user->name, route('password.request')));
        } catch (\Throwable) {
            // A troca já foi gravada; o aviso é apenas informativo.
        }
    }
}

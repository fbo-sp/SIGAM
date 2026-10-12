<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        $user = User::query()->where('email', $email)->where('status', 'active')->first();
        if ($user !== null) {
            try {
                $token = Password::broker()->createToken($user);
                Mail::to($user->email)->send(new PasswordResetMail(route('password.reset', ['token' => $token, 'email' => $user->email])));
                $audit->record('security.password_reset_requested', 'requested', $user);
            } catch (\Throwable) {
                // A resposta permanece genérica para evitar enumeração e detalhes de transporte.
            }
        }

        return back()->with('status', 'Se existir uma conta ativa com este e-mail, enviaremos as instruções de recuperação.');
    }
}

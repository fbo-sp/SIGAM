<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateAccountRequest;
use App\Services\Access\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountActivationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        return view('auth.activate-account', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function store(ActivateAccountRequest $request, string $token, UserAccountService $service): RedirectResponse
    {
        if (! $service->activate($token, $request->validated('email'), $request->validated('password'))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'O link é inválido ou expirou. Peça um novo convite à associação.']);
        }

        return redirect()->route('login')->with('status', 'Conta ativada. Entre com seu e-mail e a senha que você criou.');
    }
}

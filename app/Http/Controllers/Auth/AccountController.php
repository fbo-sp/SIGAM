<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\Access\AccountPasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.show', ['user' => $request->user()]);
    }

    public function updatePassword(ChangePasswordRequest $request, AccountPasswordService $service): RedirectResponse
    {
        $service->change($request->user(), $request->string('current_password'), $request->string('password'), $request->session()->getId());
        $request->session()->regenerate();

        return redirect()->route('account.show')->with('status', 'Senha alterada. Os outros acessos abertos foram encerrados.');
    }
}

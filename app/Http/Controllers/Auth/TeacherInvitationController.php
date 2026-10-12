<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptTeacherInvitationRequest;
use App\Services\Access\TeacherInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TeacherInvitationController extends Controller
{
    public function show(string $token, TeacherInvitationService $service): View
    {
        $invitation = $service->findByToken($token);
        abort_if($invitation === null, 404);

        return view('auth.teacher-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'existingAccount' => $invitation->user->status === 'active',
        ]);
    }

    public function accept(AcceptTeacherInvitationRequest $request, string $token, TeacherInvitationService $service): RedirectResponse
    {
        $user = $service->accept($token, $request->validated());
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Convite aceito. Seu e-mail foi verificado e o acesso de professor está ativo.');
    }

    public function decline(Request $request, string $token, TeacherInvitationService $service): RedirectResponse
    {
        $request->validate(['decline_confirmation' => ['accepted']]);
        $service->decline($token);

        return redirect()->route('login')->with('status', 'Convite recusado. O cadastro esportivo foi preservado.');
    }
}

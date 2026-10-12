<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\TeacherInvitation;
use App\Models\TeacherProfile;
use App\Services\Access\TeacherInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeacherInvitationManagementController extends Controller
{
    public function store(Request $request, Association $association, TeacherProfile $teacher, TeacherInvitationService $service): RedirectResponse
    {
        $service->issue($request->user(), $association, $teacher);

        return back()->with('status', 'Convite preparado. O envio será feito pela rotina de e-mail.');
    }

    public function reissue(Request $request, Association $association, TeacherInvitation $invitation, TeacherInvitationService $service): RedirectResponse
    {
        abort_unless($invitation->association_id === $association->id, 404);
        $service->issue($request->user(), $association, $invitation->teacher);

        return back()->with('status', 'Convite anterior invalidado e novo envio preparado.');
    }

    public function revoke(Request $request, Association $association, TeacherInvitation $invitation, TeacherInvitationService $service): RedirectResponse
    {
        $service->revoke($request->user(), $association, $invitation);

        return back()->with('status', 'Convite revogado. A conta e o perfil esportivo foram preservados.');
    }
}

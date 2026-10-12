<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalChampionshipRegistrationRequest;
use App\Http\Requests\Portal\PortalContactRequest;
use App\Models\AttendanceRecord;
use App\Models\Championship;
use App\Models\Document;
use App\Models\Student;
use App\Services\AuditService;
use App\Services\Championship\ChampionshipRegistrationService;
use App\Services\Portal\PortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalController extends Controller
{
    public function index(Request $request, PortalAccessService $access): View
    {
        $students = $access->studentsFor($request->user());

        return view('portal.index', compact('students'));
    }

    public function show(Request $request, Student $student, PortalAccessService $access): View
    {
        $student = $access->resolveStudent($request->user(), $student);
        $students = $access->studentsFor($request->user());
        $student->load([
            'person', 'primaryDojo',
            'enrollments' => fn ($query) => $query->with(['classGroup.martialArt', 'classGroup.dojo', 'classGroup.schedules'])->latest('start_date'),
            'documents' => fn ($query) => $query->where('association_id', $request->user()->association_id)->with('type')->latest('id'),
            'charges' => fn ($query) => $query->with(['payments' => fn ($payments) => $payments->latest('payment_date')])->latest('due_date'),
            'martialArts.martialArt', 'martialArts.currentRank', 'martialArts.rankHistory.rank',
            'federationMemberships.federation', 'federationMemberships.martialArt', 'federationMemberships.rank',
            'championshipRegistrations.championship', 'championshipRegistrations.result', 'championshipRegistrations.charge',
        ]);

        $attendance = AttendanceRecord::query()
            ->where('student_id', $student->id)
            ->whereHas('session.classGroup', fn ($query) => $query->where('association_id', $request->user()->association_id))
            ->with(['session.classGroup'])
            ->latest('recorded_at')
            ->limit(50)
            ->get();
        $attendanceSummary = [
            'total' => $attendance->count(),
            'present' => $attendance->where('status', 'present')->count(),
            'absent' => $attendance->where('status', 'absent')->count(),
            'justified' => $attendance->where('status', 'excused_absence')->count(),
        ];
        $martialArtIds = $student->martialArts->pluck('martial_art_id');
        $championships = Championship::query()
            ->where('association_id', $request->user()->association_id)
            ->whereIn('martial_art_id', $martialArtIds)
            ->where('status', 'open')
            ->where('allow_competitor', true)
            ->where('registration_open_at', '<=', now())
            ->where('registration_close_at', '>=', now())
            ->with('martialArt')
            ->orderBy('start_date')
            ->get();
        $canEditContact = $access->canEditContact($request->user(), $student);

        return view('portal.show', compact('student', 'students', 'attendance', 'attendanceSummary', 'championships', 'canEditContact'));
    }

    public function updateContact(PortalContactRequest $request, Student $student, PortalAccessService $access): RedirectResponse
    {
        $student = $access->resolveStudent($request->user(), $student);
        $access->updateContact($request->user(), $student, $request->validated());

        return back()->with('status', 'Dados de contato atualizados.');
    }

    public function download(Request $request, Student $student, Document $document, PortalAccessService $access, AuditService $audit): StreamedResponse
    {
        $student = $access->resolveStudent($request->user(), $student);
        abort_unless($document->association_id === $request->user()->association_id && $document->student_id === $student->id, 404);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404, 'Arquivo não encontrado.');
        $audit->record('portal.document.downloaded', 'read', $document, null, null, ['student_id' => $student->id, 'document_type_id' => $document->document_type_id]);

        return Storage::disk('local')->download($document->storage_path, $document->original_name, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function register(
        PortalChampionshipRegistrationRequest $request,
        Student $student,
        Championship $championship,
        PortalAccessService $access,
        ChampionshipRegistrationService $registrations,
    ): RedirectResponse {
        $student = $access->resolveStudent($request->user(), $student);
        $association = $request->user()->association;
        abort_unless($association && $championship->association_id === $association->id, 404);
        $data = [...$request->validated(),
            'person_id' => $student->person_id,
            'student_id' => $student->id,
            'dojo_id' => $student->primary_dojo_id,
            'participation_type' => 'competitor',
            'referee_level' => null,
            'assistant_function' => null,
        ];
        $registrations->register($association, $championship, $request->user(), $data);

        return back()->with('status', 'Inscrição enviada. Acompanhe a situação pelo portal.');
    }
}

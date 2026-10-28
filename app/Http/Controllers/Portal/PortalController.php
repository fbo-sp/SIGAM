<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalChampionshipRegistrationRequest;
use App\Http\Requests\Portal\PortalContactRequest;
use App\Models\AttendanceRecord;
use App\Models\Championship;
use App\Models\Charge;
use App\Models\ClassSession;
use App\Models\Document;
use App\Models\QuizRelease;
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
    /** Seções do portal, cada uma numa subtela. */
    public const SECTIONS = [
        'turmas' => 'Turmas e horários',
        'frequencia' => 'Frequência',
        'financeiro' => 'Financeiro',
        'progresso' => 'Progresso e quiz',
        'estudar' => 'Estudar',
        'campeonatos' => 'Campeonatos',
        'historico' => 'Histórico e federações',
        'documentos' => 'Documentos',
        'dados' => 'Meus dados',
    ];

    /** Um aluno vai direto ao início dele; o responsável com mais de um escolhe quem consultar. */
    public function index(Request $request, PortalAccessService $access): View|RedirectResponse
    {
        $students = $access->studentsFor($request->user());
        if ($students->count() === 1) {
            return redirect()->route('portal.students.show', $students->first());
        }

        return view('portal.index', compact('students'));
    }

    /** Início do aluno: indicadores principais e atalhos para as subtelas. */
    public function show(Request $request, Student $student, PortalAccessService $access): View
    {
        $student = $access->resolveStudent($request->user(), $student);
        $students = $access->studentsFor($request->user());
        $associationId = $request->user()->association_id;
        $student->load(['person', 'primaryDojo', 'martialArts' => fn ($query) => $query->where('status', 'active')->with(['martialArt', 'currentRank'])]);

        $classIds = $student->enrollments()->where('status', 'active')->pluck('class_group_id');
        $nextSession = ClassSession::query()->whereIn('class_group_id', $classIds)->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->where(fn ($query) => $query->whereDate('session_date', '>', today())
                ->orWhere(fn ($same) => $same->whereDate('session_date', today())->where('start_time', '>=', now()->format('H:i:s'))))
            ->with('classGroup:id,name')->orderBy('session_date')->orderBy('start_time')->first();
        $month = AttendanceRecord::query()->where('student_id', $student->id)
            ->whereHas('session', fn ($query) => $query->whereBetween('session_date', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])
                ->whereHas('classGroup', fn ($group) => $group->where('association_id', $associationId)))
            ->pluck('status');
        $present = $month->filter(fn (string $status) => in_array($status, ['present', 'makeup', 'trial'], true))->count();
        $outstanding = $student->charges()->whereIn('status', Charge::OUTSTANDING_STATUSES)->where('balance_amount', '>', 0);
        $indicators = [
            'next_session' => $nextSession,
            'attendance' => ['present' => $present, 'total' => $month->count(), 'percent' => $month->count() ? (int) round($present * 100 / $month->count()) : null],
            'finance' => $access->financeVisibility($student) + [
                'balance' => (string) ((clone $outstanding)->sum('balance_amount') ?: '0.00'),
                'count' => (clone $outstanding)->count(),
                'overdue' => (clone $outstanding)->whereDate('due_date', '<', today())->count(),
            ],
            'notices' => $access->unreadNotices($request->user()),
            'quiz' => QuizRelease::query()->where('student_id', $student->id)->where('status', 'open')->with('rank')->latest('id')->first(),
        ];

        return view('portal.show', compact('student', 'students', 'indicators'));
    }

    public function section(Request $request, Student $student, string $section, PortalAccessService $access): View
    {
        abort_unless(isset(self::SECTIONS[$section]) && ! in_array($section, ['progresso', 'estudar'], true), 404);
        $student = $access->resolveStudent($request->user(), $student);
        $students = $access->studentsFor($request->user());
        $associationId = $request->user()->association_id;
        $student->load(match ($section) {
            'turmas' => ['person', 'enrollments' => fn ($query) => $query->with(['classGroup.martialArt', 'classGroup.dojo', 'classGroup.schedules'])->latest('start_date')],
            'financeiro' => ['person', 'charges' => fn ($query) => $query->with(['payments' => fn ($payments) => $payments->latest('payment_date')])->latest('due_date')],
            'documentos' => ['person', 'documents' => fn ($query) => $query->where('association_id', $associationId)->with('type')->latest('id')],
            'historico' => ['person', 'martialArts.martialArt', 'martialArts.currentRank', 'martialArts.rankHistory.rank', 'federationMemberships.federation', 'federationMemberships.martialArt'],
            'campeonatos' => ['person', 'martialArts.rankHistory.rank', 'federationMemberships.federation', 'championshipRegistrations.championship', 'championshipRegistrations.result'],
            default => ['person'],
        });
        $data = ['student' => $student, 'students' => $students, 'section' => $section, 'title' => self::SECTIONS[$section]];

        if ($section === 'frequencia') {
            $attendance = AttendanceRecord::query()->where('student_id', $student->id)
                ->whereHas('session.classGroup', fn ($query) => $query->where('association_id', $associationId))
                ->with(['session.classGroup'])->latest('recorded_at')->limit(50)->get();
            $data += ['attendance' => $attendance, 'attendanceSummary' => [
                'total' => $attendance->count(),
                'present' => $attendance->where('status', 'present')->count(),
                'absent' => $attendance->where('status', 'absent')->count(),
                'justified' => $attendance->where('status', 'excused_absence')->count(),
            ]];
        }
        if ($section === 'campeonatos') {
            $data['championships'] = Championship::query()->where('association_id', $associationId)
                ->whereIn('martial_art_id', $student->martialArts->pluck('martial_art_id'))->where('status', 'open')->where('allow_competitor', true)
                ->where('registration_open_at', '<=', now())->where('registration_close_at', '>=', now())->with('martialArt')->orderBy('start_date')->get();
        }
        if ($section === 'dados') {
            $data['canEditContact'] = $access->canEditContact($request->user(), $student);
        }

        return view('portal.sections.'.$section, $data);
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

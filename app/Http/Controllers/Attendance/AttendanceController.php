<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceRequest;
use App\Http\Requests\Attendance\CancelSessionRequest;
use App\Http\Requests\Attendance\ReopenAttendanceRequest;
use App\Http\Requests\Attendance\RescheduleSessionRequest;
use App\Http\Requests\Attendance\SessionVisitorRequest;
use App\Models\Association;
use App\Models\ClassSession;
use App\Models\ClassSessionVisitor;
use App\Models\TeacherProfile;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\SessionVisitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        // Sem filtro nenhum a tela abre no dia de hoje; com a data em branco mostra todas as datas.
        $filters = ['date' => $request->has('date') ? (string) $request->input('date') : today()->toDateString(), 'dojo' => $request->integer('dojo'), 'turma' => $request->integer('turma'), 'professor' => $request->integer('professor')];
        $sessions = ClassSession::query()
            ->whereHas('classGroup', fn ($query) => $query->where('association_id', $association->id)
                ->when($filters['dojo'], fn ($group) => $group->where('dojo_id', $filters['dojo']))
                ->when($filters['turma'], fn ($group) => $group->whereKey($filters['turma'])))
            ->when($filters['professor'], fn ($query) => $query->where(fn ($teacher) => $teacher
                ->where('planned_teacher_id', $filters['professor'])->orWhere('actual_teacher_id', $filters['professor'])
                ->orWhereExists(fn ($assistant) => $assistant->selectRaw('1')->from('class_group_teachers')
                    ->whereColumn('class_group_teachers.class_group_id', 'class_sessions.class_group_id')
                    ->where('class_group_teachers.teacher_profile_id', $filters['professor'])->where('class_group_teachers.role_type', 'assistant')
                    ->whereColumn('class_group_teachers.start_date', '<=', 'class_sessions.session_date')
                    ->where(fn ($end) => $end->whereNull('class_group_teachers.end_date')->orWhereColumn('class_group_teachers.end_date', '>=', 'class_sessions.session_date')))))
            ->when($filters['date'] !== '', fn ($query) => $query->whereDate('session_date', $filters['date']))
            ->with(['classGroup.dojo', 'classGroup.martialArt', 'plannedTeacher.person:id,full_name,social_name,photo_path'])
            ->withCount(['visitors' => fn ($query) => $query->whereNotIn('status', ['revoked'])])
            ->orderBy('session_date')->orderBy('start_time')->paginate(30)->withQueryString();
        $dojos = $association->dojos()->orderBy('name')->get(['id', 'name']);
        $groups = $association->classGroups()->when($filters['dojo'], fn ($query) => $query->where('dojo_id', $filters['dojo']))
            ->with('dojo:id,name')->orderBy('name')->get(['id', 'name', 'dojo_id']);
        $teachers = TeacherProfile::query()->whereHas('person.associations', fn ($query) => $query->whereKey($association->id))
            ->with('person:id,full_name,social_name')->get()->sortBy('person.full_name');

        return view('attendance.index', compact('association', 'sessions', 'filters', 'dojos', 'groups', 'teachers'));
    }

    public function show(Association $association, ClassSession $session, AttendanceService $service): View
    {
        $this->ensureSession($association, $session);
        $session->load(['classGroup.dojo', 'records']);
        $roster = $service->roster($session);
        $records = $session->records->keyBy('student_id');
        $canManageVisitors = auth()->user()->hasPermission('visitor.manage', $association->id)
            && $session->attendance_state === 'open' && $session->status === 'planned';

        return view('attendance.show', compact('association', 'session', 'roster', 'records', 'canManageVisitors'));
    }

    public function addVisitor(SessionVisitorRequest $request, Association $association, ClassSession $session, SessionVisitorService $service): RedirectResponse
    {
        $this->ensureSession($association, $session);
        $service->authorize($association, $session, $request->user(), $request->validated('registration_number'));

        return back()->with('status', 'Visitante incluído somente nesta aula, sem matrícula ou cobrança.');
    }

    public function revokeVisitor(Request $request, Association $association, ClassSession $session, ClassSessionVisitor $visitor, SessionVisitorService $service): RedirectResponse
    {
        $this->ensureSession($association, $session);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->revoke($association, $session, $visitor, $request->user(), $data['reason']);

        return back()->with('status', 'Participação visitante revogada. O histórico foi preservado.');
    }

    public function store(AttendanceRequest $request, Association $association, ClassSession $session, AttendanceService $service): RedirectResponse
    {
        $this->ensureSession($association, $session);
        $service->save($association, $session, $request->user(), $request->validated('records'), $request->boolean('close_attendance'));

        return back()->with('status', $request->boolean('close_attendance') ? 'Chamada finalizada.' : 'Chamada salva.');
    }

    public function reopen(ReopenAttendanceRequest $request, Association $association, ClassSession $session, AttendanceService $service): RedirectResponse
    {
        $this->ensureSession($association, $session);
        $service->reopen($association, $session, $request->user(), $request->validated('reason'));

        return back()->with('status', 'Chamada reaberta. Faça os ajustes e finalize novamente.');
    }

    public function reschedule(RescheduleSessionRequest $request, Association $association, ClassSession $session, AttendanceService $service): RedirectResponse
    {
        $this->ensureSession($association, $session);
        $new = $service->reschedule($association, $session, $request->validated());

        return redirect()->route('associations.attendance.show', [$association, $new])->with('status', 'Aula reagendada sem alterar o histórico da original.');
    }

    public function cancel(CancelSessionRequest $request, Association $association, ClassSession $session, AttendanceService $service): RedirectResponse
    {
        $this->ensureSession($association, $session);
        $service->cancel($association, $session, $request->validated('reason'));

        return back()->with('status', 'Aula cancelada.');
    }

    private function ensureSession(Association $association, ClassSession $session): void
    {
        abort_unless($session->classGroup()->where('association_id', $association->id)->exists(), 404);
    }
}

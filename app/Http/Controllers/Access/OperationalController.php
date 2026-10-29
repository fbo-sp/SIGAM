<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ReopenAttendanceRequest;
use App\Http\Requests\Attendance\SessionVisitorRequest;
use App\Models\ClassSession;
use App\Models\ClassSessionVisitor;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\ProgressionSetting;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Services\Access\AccessAdministration;
use App\Services\Access\OperationalScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\SessionVisitorService;
use App\Services\Progression\SessionAssessmentService;
use App\Services\Progression\SessionTechniqueService;
use App\Services\Progression\TechniqueCatalogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationalController extends Controller
{
    public function __construct(private readonly OperationalScope $scope) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Dojo::query()->where('status', 'active')->whereHas('association', fn ($q) => $q->where('status', 'active'));
        if ($user->association_id !== null) {
            $query->where('association_id', $user->association_id);
        }
        if (! $user->hasPermission('dojo.view', $user->association_id)) {
            $grants = DojoRoleAssignment::query()->select('dojo_id')->where('user_id', $user->id)->where('association_id', $user->association_id)
                ->whereNull('revoked_at')->whereDate('start_date', '<=', today())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->whereHas('role', fn ($q) => $q->whereIn('scope_type', ['dojo', 'teacher'])->where('status', 'active')
                    ->where(fn ($r) => $r->whereNull('association_id')->orWhere('association_id', $user->association_id))
                    ->whereHas('permissions', fn ($p) => $p->where('code', 'dojo.access')));
            $query->whereIn('id', $grants);
        }

        return view('operation.dojos', ['dojos' => $query->with('association')->orderBy('name')->paginate(20)]);
    }

    public function show(Request $request, Dojo $dojo)
    {
        $this->enter($request, $dojo);
        $capabilities = collect(OperationalScope::DOJO_PERMISSIONS)->mapWithKeys(fn ($permission) => [
            $permission => $this->scope->canDojo($request->user(), $dojo, $permission)
                || $this->scope->canTeacher($request->user(), $dojo, $permission),
        ]);

        return view('operation.home', compact('dojo', 'capabilities'));
    }

    public function students(Request $request, Dojo $dojo)
    {
        $this->enter($request, $dojo);
        $students = $this->scope->students($request->user(), $dojo)->with([
            'person:id,full_name,social_name,photo_path',
            'enrollments' => fn ($query) => $query->where('status', 'active')->whereHas('classGroup', fn ($group) => $group->where('dojo_id', $dojo->id))->with('classGroup:id,name'),
        ])->withExists(['person as is_teacher' => fn ($query) => $query->whereHas('teacherProfile')])
            ->orderBy('registration_number')->paginate(25);

        $canManage = $this->scope->canDojo($request->user(), $dojo, 'student.manage');
        $canEditPeople = $request->user()->hasPermission('person.manage', $dojo->association_id);
        $canProgress = $this->scope->canDojo($request->user(), $dojo, 'assessment.view');

        return view('operation.students', compact('dojo', 'students', 'canManage', 'canEditPeople', 'canProgress'));
    }

    public function sessions(Request $request, Dojo $dojo)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'session.view') || $this->scope->canTeacher($request->user(), $dojo, 'session.view'), 403);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $sessions = $this->scope->sessions($request->user(), $dojo)->with('classGroup:id,name')
            ->withCount(['visitors' => fn ($query) => $query->whereNotIn('status', ['revoked'])])
            ->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('session_date', $date))
            ->orderByDesc('session_date')->orderBy('start_time')->paginate(25)->withQueryString();

        return view('operation.sessions', compact('dojo', 'sessions'));
    }

    public function session(Request $request, Dojo $dojo, ClassSession $session, AttendanceService $attendance)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->sessions($request->user(), $dojo)->whereKey($session->id)->exists(), 404);
        $session->load('classGroup.dojo', 'classGroup.martialArt');
        $roster = $attendance->roster($session);
        $records = $session->records()->whereIn('student_id', $roster->pluck('student.id'))->get()->keyBy('student_id');
        $canRecord = $this->scope->sessions($request->user(), $dojo, 'attendance.manage')->whereKey($session->id)->exists()
            && $session->attendance_state === 'open' && ! in_array($session->status, ['cancelled', 'rescheduled'], true)
            && ! $session->isBeforeAttendanceDay();
        $canReopen = $session->attendance_state === 'closed' && $this->scope->sessions($request->user(), $dojo, 'attendance.reopen')->whereKey($session->id)->exists();
        $canManageVisitors = $this->scope->sessions($request->user(), $dojo, 'visitor.manage')->whereKey($session->id)->exists()
            && $session->attendance_state === 'open' && $session->status === 'planned';

        $enrollUrl = $this->scope->canDojo($request->user(), $dojo, 'enrollment.manage')
            ? route('operation.classes.show', [$dojo, $session->classGroup]).'#alunos' : null;

        $techniqueService = app(SessionTechniqueService::class);
        $catalog = app(TechniqueCatalogService::class);
        $catalogItems = $catalog->items($dojo->association, $session->classGroup->martialArt);
        $inactive = $catalog->inactiveIds($catalogItems);
        $techniqueTree = collect($catalog->tree($catalogItems->reject(fn ($item) => isset($inactive[$item->id]))));
        $markedTechniques = $techniqueService->marked($session);
        $allowGroupPick = ProgressionSetting::for($dojo->association_id)->allow_group_pick;
        $canMarkTechniques = $techniqueTree->isNotEmpty() && $techniqueService->canMark($request->user(), $dojo, $session);

        $assessUrl = app(SessionAssessmentService::class)->available($request->user(), $dojo, $session) ? route('operation.session.assess', [$dojo, $session]) : null;

        return view('operation.session', compact('dojo', 'session', 'roster', 'records', 'canRecord', 'canReopen', 'canManageVisitors', 'enrollUrl', 'techniqueTree', 'markedTechniques', 'canMarkTechniques', 'assessUrl', 'allowGroupPick'));
    }

    public function addVisitor(SessionVisitorRequest $request, Dojo $dojo, ClassSession $session, SessionVisitorService $service)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->sessions($request->user(), $dojo, 'visitor.manage')->whereKey($session->id)->exists(), 404);
        $service->authorize($dojo->association, $session, $request->user(), $request->validated('registration_number'));

        return back()->with('status', 'Visitante incluído somente nesta aula, sem matrícula ou cobrança.');
    }

    public function revokeVisitor(Request $request, Dojo $dojo, ClassSession $session, ClassSessionVisitor $visitor, SessionVisitorService $service)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->sessions($request->user(), $dojo, 'visitor.manage')->whereKey($session->id)->exists(), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->revoke($dojo->association, $session, $visitor, $request->user(), $data['reason']);

        return back()->with('status', 'Participação visitante revogada.');
    }

    public function saveAttendance(Request $request, Dojo $dojo, ClassSession $session, AttendanceService $service)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->sessions($request->user(), $dojo, 'attendance.manage')->whereKey($session->id)->exists(), 404);
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1'], 'records.*.student_id' => ['required', 'integer', 'distinct'],
            'records.*.status' => ['nullable', Rule::in(AttendanceService::RECORDABLE_STATUSES)],
            'close_attendance' => ['nullable', 'boolean'],
            'staff_lead' => ['nullable', 'integer'], 'staff_assistants' => ['nullable', 'array'], 'staff_assistants.*' => ['integer'],
        ]);
        $service->save($dojo->association, $session, $request->user(), $data['records'], $request->boolean('close_attendance'),
            $request->filled('staff_lead') ? ['lead' => $data['staff_lead'], 'assistants' => $data['staff_assistants'] ?? []] : null);

        return redirect()->route('operation.session', [$dojo, $session])->with('status', 'Chamada salva.');
    }

    public function reopenAttendance(ReopenAttendanceRequest $request, Dojo $dojo, ClassSession $session, AttendanceService $service)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->sessions($request->user(), $dojo, 'attendance.reopen')->whereKey($session->id)->exists(), 404);
        $service->reopen($dojo->association, $session, $request->user(), $request->validated('reason'));

        return redirect()->route('operation.session', [$dojo, $session])->with('status', 'Chamada reaberta. Revise as presenças e finalize de novo.');
    }

    public function teachers(Request $request, Dojo $dojo)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'teacher.view'), 403);
        $teachers = TeacherProfile::query()->where('status', 'active')->whereIn('id', TeacherAssociationMembership::query()->select('teacher_profile_id')
            ->where('association_id', $dojo->association_id)->where('status', 'active'))->with('person:id,full_name')->get();
        $links = DojoTeacherAssignment::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)
            ->with('teacher.person:id,full_name,photo_path')->orderByDesc('start_date')->paginate(25);
        $canAssign = $this->scope->canDojo($request->user(), $dojo, 'teacher.assign');

        $canEditPeople = $request->user()->hasPermission('person.manage', $dojo->association_id);

        return view('operation.teachers', compact('dojo', 'teachers', 'links', 'canAssign', 'canEditPeople'));
    }

    public function linkTeacher(Request $request, Dojo $dojo, AccessAdministration $service)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'teacher.assign'), 403);
        $data = $request->validate(['teacher_profile_id' => ['required', 'integer'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $service->linkTeacher($request->user(), $dojo, $data);

        return back()->with('status', 'Professor vinculado ao dojo. Se ele já tem conta no sistema, o acesso de professor a este dojo foi liberado no mesmo período.');
    }

    public function unlinkTeacher(Request $request, Dojo $dojo, DojoTeacherAssignment $link, AccessAdministration $service)
    {
        $this->enter($request, $dojo);
        $service->unlinkTeacher($request->user(), $dojo, $link);

        return back()->with('status', 'Vínculo encerrado. O histórico foi preservado.');
    }

    private function enter(Request $request, Dojo $dojo): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}

<?php

namespace App\Http\Controllers\Classes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Classes\ClassGroupRequest;
use App\Http\Requests\Classes\ClassGroupUpdateRequest;
use App\Http\Requests\Classes\ClassTeacherRequest;
use App\Http\Requests\Classes\EndEnrollmentRequest;
use App\Http\Requests\Classes\EnrollmentRequest;
use App\Http\Requests\Classes\EnrollmentStartRequest;
use App\Http\Requests\Classes\FutureSessionsRequest;
use App\Http\Requests\Classes\PastSessionsRequest;
use App\Http\Requests\Classes\ScheduleSetRequest;
use App\Http\Requests\Classes\ScheduleTimeCorrectionRequest;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSchedule;
use App\Models\Dojo;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\TeacherProfile;
use App\Services\Access\OperationalScope;
use App\Services\Attendance\FutureSessionMaintainer;
use App\Services\Classes\ClassGroupService;
use App\Services\Classes\EnrollmentStartCorrectionService;
use App\Services\Classes\ScheduleCorrectionService;
use App\Services\Classes\TeacherWorkspaceService;
use App\Services\Progression\AssessmentService;
use App\Services\Progression\ProgressionPanelService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Turmas no contexto do dojo: o gestor do dojo administra, o professor consulta as suas. */
class DojoClassController extends Controller
{
    public function __construct(private readonly OperationalScope $scope) {}

    public function index(Request $request, Dojo $dojo): View
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'class.view') || $this->scope->canTeacher($request->user(), $dojo, 'class.view'), 403);
        $classes = $this->scope->classes($request->user(), $dojo)->with(['martialArt:id,name', 'mainTeacher.person:id,full_name,social_name,photo_path', 'currentAssistants.teacher.person:id,full_name,social_name,photo_path'])
            ->withCount(['enrollments' => fn ($query) => $query->where('status', 'active')])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderBy('name')->paginate(25);
        $canManage = $this->scope->canDojo($request->user(), $dojo, 'class.manage');

        return view('operation.classes', compact('dojo', 'classes', 'canManage'));
    }

    public function create(Request $request, Dojo $dojo): View
    {
        $this->authorizeManage($request, $dojo, 'class.manage');

        return view('classes.create', [
            ...$this->formOptions($dojo),
            'association' => $dojo->association,
            'fixedDojo' => $dojo,
            'formAction' => route('operation.classes.store', $dojo),
            'cancelUrl' => route('operation.classes', $dojo),
            'crumbs' => [[$dojo->name, route('operation.home', $dojo)], ['Turmas', route('operation.classes', $dojo)]],
        ]);
    }

    public function store(ClassGroupRequest $request, Dojo $dojo, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage');
        $group = $service->create($dojo->association, $request->validated());

        return redirect()->route('operation.classes.show', [$dojo, $group])
            ->with('status', "Turma criada e próximas {$group->future_session_target} aulas preparadas. Agora matricule os alunos.");
    }

    public function show(Request $request, Dojo $dojo, ClassGroup $classGroup): View
    {
        $this->enter($request, $dojo);
        $this->ensureVisible($request, $dojo, $classGroup);
        $user = $request->user();
        $classGroup->load(['martialArt', 'mainTeacher.person', 'schedules.teacher.person']);
        $canManage = $this->scope->canDojo($user, $dojo, 'class.manage');
        $canEnroll = $this->scope->canDojo($user, $dojo, 'enrollment.manage');
        $canCorrectStart = $user->hasPermission('enrollment.manage', $dojo->association_id);

        $canEditStudents = $this->scope->canDojo($user, $dojo, 'student.manage');
        $canEditPeople = $user->hasPermission('person.manage', $dojo->association_id);
        $enrollments = $classGroup->enrollments()->with(['student' => fn ($query) => $query->with(['person:id,full_name,social_name,birth_date,photo_path,phone,mobile,emergency_contact_name,emergency_contact_phone',
            'guardians' => fn ($guardians) => $guardians->where(fn ($g) => $g->where('is_legal_guardian', true)->orWhere('is_primary', true))
                ->where(fn ($g) => $g->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->with('person:id,full_name,social_name,phone,mobile')])
            ->withExists(['person as is_teacher' => fn ($person) => $person->whereHas('teacherProfile')])])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('start_date')->limit(200)->get();
        $activeIds = $enrollments->where('status', 'active')->pluck('student_id');
        $assignments = $classGroup->teacherAssignments()->with('teacher.person:id,full_name,social_name,photo_path')->orderByDesc('start_date')->get();
        $visibleSessions = $this->scope->sessions($user, $dojo)->where('class_group_id', $classGroup->id);
        $upcoming = (clone $visibleSessions)->whereDate('session_date', '>=', today())->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->orderBy('session_date')->orderBy('start_time')->limit(8)->get();
        $past = (clone $visibleSessions)->whereDate('session_date', '<', today())->withCount('records')
            ->orderByDesc('session_date')->orderByDesc('start_time')->limit(10)->get();

        $candidates = $canEnroll ? $this->scope->students($user, $dojo)->where('status', 'active')->whereNotIn('students.id', $activeIds)
            ->with('person:id,full_name,social_name')->orderBy('registration_number')->limit(300)->get() : collect();
        $dojoTeachers = $canManage ? TeacherProfile::query()->where('status', 'active')
            ->whereIn('id', DojoTeacherAssignment::query()->select('teacher_profile_id')->where('association_id', $dojo->association_id)
                ->where('dojo_id', $dojo->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today())))
            ->whereKeyNot($classGroup->main_teacher_id ?? 0)->with('person:id,full_name')->get()->sortBy('person.full_name') : collect();
        $scheduleTeachers = $canManage ? $this->formOptions($dojo)['teachers'] : collect();
        $canAssess = app(AssessmentService::class)->teachesClass($user, $dojo, $classGroup);
        $canProgress = $canAssess || $this->scope->canDojo($user, $dojo, 'assessment.view');
        $canCoverage = app(ProgressionPanelService::class)->dojoClasses($user, $dojo)->whereKey($classGroup->id)->exists();
        $canAnnounce = $classGroup->status === 'active' && ($this->scope->canDojo($user, $dojo, 'announcement.send')
            || ($this->scope->canTeacher($user, $dojo, 'announcement.send') && app(TeacherWorkspaceService::class)->groups($user)->whereKey($classGroup->id)->exists()));

        return view('operation.class', compact(
            'dojo', 'classGroup', 'enrollments', 'assignments', 'upcoming', 'past', 'candidates', 'dojoTeachers',
            'scheduleTeachers', 'canManage', 'canEnroll', 'canCorrectStart', 'canEditStudents', 'canEditPeople', 'canAssess', 'canProgress', 'canCoverage', 'canAnnounce',
        ));
    }

    public function edit(Request $request, Dojo $dojo, ClassGroup $classGroup): View
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);

        return view('operation.class-edit', ['dojo' => $dojo, 'classGroup' => $classGroup, 'teachers' => $this->formOptions($dojo)['teachers']]);
    }

    public function update(ClassGroupUpdateRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        $newTeacherId = $request->filled('main_teacher_id') ? $request->integer('main_teacher_id') : null;
        $promoted = $newTeacherId !== null && $newTeacherId !== $classGroup->main_teacher_id && $classGroup->teacherAssignments()
            ->where('teacher_profile_id', $newTeacherId)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->exists();
        $service->update($dojo->association, $classGroup, $request->validated());

        return redirect()->route('operation.classes.show', [$dojo, $classGroup])->with('status', $promoted
            ? 'Turma atualizada. O professor passou de auxiliar a principal, e a designação de auxiliar foi encerrada.'
            : 'Turma atualizada.');
    }

    public function enroll(EnrollmentRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'enrollment.manage', $classGroup);
        abort_unless($this->scope->students($request->user(), $dojo)->whereKey($request->integer('student_id'))->exists(), 404);
        $service->enroll($dojo->association, $classGroup, $request->validated());

        return back()->with('status', 'Aluno matriculado na turma.');
    }

    public function endEnrollment(EndEnrollmentRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassEnrollment $enrollment, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'enrollment.manage', $classGroup);
        abort_unless($enrollment->class_group_id === $classGroup->id, 404);
        $service->endEnrollment($dojo->association, $enrollment, $request->validated());

        return back()->with('status', 'Matrícula encerrada. As chamadas anteriores foram preservadas.');
    }

    /** Só administrador do sistema e gestor da associação: o papel local do dojo não corrige o início. */
    public function correctEnrollmentStart(EnrollmentStartRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassEnrollment $enrollment, EnrollmentStartCorrectionService $service): RedirectResponse
    {
        $this->enter($request, $dojo);
        abort_unless($classGroup->dojo_id === $dojo->id && $request->user()->hasPermission('enrollment.manage', $dojo->association_id), 403);
        abort_unless($enrollment->class_group_id === $classGroup->id, 404);

        return back()->with(EnrollmentStartCorrectionService::flash($service->correct($dojo->association, $enrollment, $request->validated())));
    }

    public function assignTeacher(ClassTeacherRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        $service->assignTeacher($dojo->association, $classGroup, $request->validated());

        return back()->with('status', 'Professor auxiliar designado. Ele passa a ver e fazer a chamada das aulas desta turma.');
    }

    public function endTeacher(Request $request, Dojo $dojo, ClassGroup $classGroup, ClassGroupTeacher $assignment, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        abort_unless($assignment->class_group_id === $classGroup->id, 404);
        $data = $request->validate(['end_date' => ['required', 'date_format:Y-m-d']]);
        $service->endTeacherAssignment($dojo->association, $assignment, $data);

        return back()->with('status', 'Designação do professor auxiliar encerrada.');
    }

    public function replaceScheduleSet(ScheduleSetRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        $result = $service->replaceScheduleSet($dojo->association, $classGroup, $request->validated());

        return back()->with('status', "Nova agenda salva. {$result['created']} aula(s) criada(s), {$result['cancelled']} cancelada(s) e {$result['preserved']} preservada(s).");
    }

    public function correctScheduleTime(ScheduleTimeCorrectionRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassSchedule $schedule, ScheduleCorrectionService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        abort_unless($schedule->class_group_id === $classGroup->id && $schedule->status === 'active', 404);
        $result = $service->correct($dojo->association, $classGroup, $schedule, $request->validated());

        return back()->with('status', 'Horário corrigido para '.$request->string('start_time').' às '.$request->string('end_time')." em {$result['updated']} aula(s).");
    }

    public function refreshFutureSessions(FutureSessionsRequest $request, Dojo $dojo, ClassGroup $classGroup, FutureSessionMaintainer $maintainer): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        $result = $maintainer->updateTarget($classGroup, $request->integer('future_session_target'));

        return back()->with('status', "Agenda atualizada: {$result['created']} aula(s) criada(s).");
    }

    public function generatePastSessions(PastSessionsRequest $request, Dojo $dojo, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->authorizeManage($request, $dojo, 'class.manage', $classGroup);
        $result = $service->generatePastSessions($dojo->association, $classGroup, $request->validated());
        $message = "{$result['created']} aula(s) passada(s) criada(s).";
        if ($result['start_date_moved']) {
            $message .= ' O início da turma passou para '.Carbon::parse($request->validated('from'))->format('d/m/Y').'.';
        }

        return redirect()->to(route('operation.classes.show', [$dojo, $classGroup]).'#aulas')->with('status', $message);
    }

    private function formOptions(Dojo $dojo): array
    {
        $associationId = $dojo->association_id;

        return [
            'dojos' => collect([$dojo]),
            'martialArts' => MartialArt::query()->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $associationId))->where('status', 'active')->orderBy('name')->get(),
            'teachers' => TeacherProfile::query()->where('status', 'active')->whereHas('person.associations', fn ($query) => $query->whereKey($associationId))->with('person')->get()->sortBy('person.full_name'),
        ];
    }

    private function authorizeManage(Request $request, Dojo $dojo, string $permission, ?ClassGroup $group = null): void
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, $permission), 403);
        if ($group !== null) {
            $this->ensureVisible($request, $dojo, $group);
        }
    }

    private function ensureVisible(Request $request, Dojo $dojo, ClassGroup $group): void
    {
        abort_unless($group->association_id === $dojo->association_id && $group->dojo_id === $dojo->id
            && $this->scope->classes($request->user(), $dojo)->whereKey($group->id)->exists(), 404);
    }

    private function enter(Request $request, Dojo $dojo): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}

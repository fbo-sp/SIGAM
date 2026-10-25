<?php

namespace App\Http\Controllers\Classes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Classes\ClassGroupRequest;
use App\Http\Requests\Classes\EnrollmentRequest;
use App\Http\Requests\Classes\FutureSessionsRequest;
use App\Http\Requests\Classes\ScheduleRequest;
use App\Http\Requests\Classes\ScheduleSetRequest;
use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Services\Attendance\FutureSessionMaintainer;
use App\Services\Classes\ClassGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ClassGroupController extends Controller
{
    public function index(Association $association): View
    {
        $groups = $association->classGroups()->with(['dojo', 'martialArt', 'mainTeacher.person', 'currentAssistants.teacher.person'])->withCount(['enrollments' => fn ($query) => $query->where('status', 'active')])->orderBy('name')->paginate(20);

        return view('classes.index', compact('association', 'groups'));
    }

    public function create(Association $association): View
    {
        return view('classes.create', $this->formData($association));
    }

    public function store(ClassGroupRequest $request, Association $association, ClassGroupService $service): RedirectResponse
    {
        $group = $service->create($association, $request->validated());

        return redirect()->route('associations.classes.show', [$association, $group])->with('status', "Turma criada e próximas {$group->future_session_target} aulas preparadas.");
    }

    public function show(Association $association, ClassGroup $classGroup): View
    {
        $this->ensureGroup($association, $classGroup);
        $classGroup->load(['dojo', 'martialArt', 'mainTeacher.person', 'currentAssistants.teacher.person', 'schedules.teacher.person', 'enrollments.student.person']);
        $students = Student::query()->where('association_id', $association->id)->where('status', 'active')->with('person')->orderBy('registration_number')->get();
        $nextSessions = $classGroup->sessions()->whereDate('session_date', '>=', today())->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->orderBy('session_date')->orderBy('start_time')->limit(8)->get();
        $teachers = TeacherProfile::query()->whereHas('person.associations', fn ($query) => $query->whereKey($association->id))->with('person')->get();

        return view('classes.show', compact('association', 'classGroup', 'students', 'nextSessions', 'teachers'));
    }

    public function replaceScheduleSet(ScheduleSetRequest $request, Association $association, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->ensureGroup($association, $classGroup);
        $result = $service->replaceScheduleSet($association, $classGroup, $request->validated());

        return back()->with('status', "Nova agenda salva. {$result['created']} aula(s) criada(s), {$result['cancelled']} cancelada(s) e {$result['preserved']} preservada(s).");
    }

    public function replaceSchedule(ScheduleRequest $request, Association $association, ClassGroup $classGroup, ClassSchedule $schedule, ClassGroupService $service): RedirectResponse
    {
        $this->ensureGroup($association, $classGroup);
        abort_unless($schedule->class_group_id === $classGroup->id && $schedule->status === 'active', 404);
        $service->replaceSchedule($association, $classGroup, $schedule, $request->validated());

        return back()->with('status', 'Novo horário iniciado sem alterar o histórico anterior.');
    }

    public function enroll(EnrollmentRequest $request, Association $association, ClassGroup $classGroup, ClassGroupService $service): RedirectResponse
    {
        $this->ensureGroup($association, $classGroup);
        $service->enroll($association, $classGroup, $request->validated());

        return back()->with('status', 'Aluno matriculado na turma.');
    }

    public function refreshFutureSessions(FutureSessionsRequest $request, Association $association, ClassGroup $classGroup, FutureSessionMaintainer $maintainer): RedirectResponse
    {
        $this->ensureGroup($association, $classGroup);
        $result = $maintainer->updateTarget($classGroup, $request->integer('future_session_target'));
        $message = "Agenda atualizada: {$result['created']} aula(s) criada(s).";
        if ($result['missing'] > 0) {
            $message .= " Faltam {$result['missing']} porque a turma ou a agenda termina antes.";
        }

        return back()->with('status', $message);
    }

    private function formData(Association $association): array
    {
        return ['association' => $association, 'dojos' => $association->dojos()->where('status', 'active')->orderBy('name')->get(), 'martialArts' => MartialArt::query()->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->where('status', 'active')->get(), 'teachers' => TeacherProfile::query()->whereHas('person.associations', fn ($query) => $query->whereKey($association->id))->with('person')->get()];
    }

    private function ensureGroup(Association $association, ClassGroup $group): void
    {
        abort_unless($group->association_id === $association->id, 404);
    }
}

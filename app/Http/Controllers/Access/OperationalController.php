<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\SessionVisitorRequest;
use App\Models\ClassSession;
use App\Models\ClassSessionVisitor;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Services\Access\AccessAdministration;
use App\Services\Access\OperationalScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\SessionVisitorService;
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
        $students = $this->scope->students($request->user(), $dojo)->with('person:id,full_name,social_name')->orderBy('registration_number')->paginate(25);

        $canManage = $this->scope->canDojo($request->user(), $dojo, 'student.manage');

        return view('operation.students', compact('dojo', 'students', 'canManage'));
    }

    public function classes(Request $request, Dojo $dojo)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'class.view') || $this->scope->canTeacher($request->user(), $dojo, 'class.view'), 403);
        $classes = $this->scope->classes($request->user(), $dojo)->orderBy('name')->paginate(25);

        return view('operation.classes', compact('dojo', 'classes'));
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
        $session->load('classGroup.dojo');
        $roster = $attendance->roster($session);
        $records = $session->records()->whereIn('student_id', $roster->pluck('student.id'))->get()->keyBy('student_id');
        $canRecord = $this->scope->sessions($request->user(), $dojo, 'attendance.manage')->whereKey($session->id)->exists()
            && $session->attendance_state === 'open' && ! in_array($session->status, ['cancelled', 'rescheduled'], true);
        $canManageVisitors = $this->scope->sessions($request->user(), $dojo, 'visitor.manage')->whereKey($session->id)->exists()
            && $session->attendance_state === 'open' && $session->status === 'planned';

        return view('operation.session', compact('dojo', 'session', 'roster', 'records', 'canRecord', 'canManageVisitors'));
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
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'excused_absence', 'makeup', 'trial'])],
            'close_attendance' => ['nullable', 'boolean'],
        ]);
        $service->save($dojo->association, $session, $request->user(), $data['records'], $request->boolean('close_attendance'));

        return redirect()->route('operation.session', [$dojo, $session])->with('status', 'Chamada salva.');
    }

    public function teachers(Request $request, Dojo $dojo)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'teacher.view'), 403);
        $teachers = TeacherProfile::query()->where('status', 'active')->whereIn('id', TeacherAssociationMembership::query()->select('teacher_profile_id')
            ->where('association_id', $dojo->association_id)->where('status', 'active'))->with('person:id,full_name')->get();
        $links = DojoTeacherAssignment::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)
            ->with('teacher.person:id,full_name')->orderByDesc('start_date')->paginate(25);
        $canAssign = $this->scope->canDojo($request->user(), $dojo, 'teacher.assign');

        return view('operation.teachers', compact('dojo', 'teachers', 'links', 'canAssign'));
    }

    public function linkTeacher(Request $request, Dojo $dojo, AccessAdministration $service)
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->canDojo($request->user(), $dojo, 'teacher.assign'), 403);
        $data = $request->validate(['teacher_profile_id' => ['required', 'integer'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $service->linkTeacher($request->user(), $dojo, $data);

        return back()->with('status', 'Professor vinculado ao dojo. Isso não cria uma conta nem concede acesso ao sistema.');
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

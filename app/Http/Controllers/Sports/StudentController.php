<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Classes\EndEnrollmentRequest;
use App\Http\Requests\Classes\EnrollmentStartRequest;
use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Student;
use App\Services\Classes\ClassGroupService;
use App\Services\Classes\EnrollmentStartCorrectionService;
use App\Services\Sports\GuardianService;
use App\Services\Sports\StudentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $students = $association->students()->with(['person', 'primaryDojo'])->when($request->filled('q'), function ($query) use ($request): void {
            $term = '%'.trim((string) $request->q).'%';
            $query->where(fn ($search) => $search->whereHas('person', fn ($personQuery) => $personQuery->where('full_name', 'like', $term))->orWhere('registration_number', 'like', $term));
        })->orderByDesc('id')->paginate(20)->withQueryString();

        return view('sports.students.index', compact('association', 'students'));
    }

    public function show(Request $request, Association $association, Student $student): View
    {
        $this->ensureStudent($association, $student);
        $student->load(['person.user', 'primaryDojo', 'guardians.person.user', 'martialArts.martialArt', 'martialArts.currentRank', 'martialArts.rankHistory.rank', 'federationMemberships.federation', 'federationMemberships.martialArt', 'federationMemberships.rank', 'championshipRegistrations.championship', 'championshipRegistrations.result', 'championshipRegistrations.charge']);
        $historyFilters = ['year' => $request->filled('history_year') ? $request->integer('history_year') : null, 'martial_art_id' => $request->filled('history_martial_art_id') ? $request->integer('history_martial_art_id') : null];
        $enrollments = $student->enrollments()->with('classGroup.dojo:id,name', 'classGroup.martialArt:id,name')
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('start_date')->get();
        $currentDojoIds = $student->dojoHistory()->whereDate('start_date', '<=', today())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->pluck('dojo_id');
        $availableClasses = ClassGroup::query()->where('association_id', $association->id)->whereIn('dojo_id', $currentDojoIds)->where('status', 'active')
            ->whereNotIn('id', $enrollments->where('status', 'active')->pluck('class_group_id'))->with('dojo:id,name')->orderBy('name')->get();

        return view('sports.students.show', [
            ...compact('association', 'student', 'historyFilters', 'enrollments', 'availableClasses'),
            ...app(GuardianService::class)->viewData($request->user(), $student),
        ]);
    }

    public function enroll(Request $request, Association $association, Student $student, ClassGroupService $service): RedirectResponse
    {
        $this->ensureStudent($association, $student);
        $data = $request->validate(['class_group_id' => ['required', 'integer'], 'start_date' => ['required', 'date_format:Y-m-d']]);
        $group = ClassGroup::query()->where('association_id', $association->id)->findOrFail($data['class_group_id']);
        $service->enroll($association, $group, ['student_id' => $student->id, 'start_date' => $data['start_date']]);

        return back()->with('status', "Aluno matriculado na turma {$group->name}.");
    }

    public function endEnrollment(EndEnrollmentRequest $request, Association $association, Student $student, ClassEnrollment $enrollment, ClassGroupService $service): RedirectResponse
    {
        $this->ensureStudent($association, $student);
        abort_unless($enrollment->student_id === $student->id, 404);
        $service->endEnrollment($association, $enrollment, $request->validated());

        return back()->with('status', 'Matrícula encerrada. As chamadas anteriores foram preservadas.');
    }

    public function correctEnrollmentStart(EnrollmentStartRequest $request, Association $association, Student $student, ClassEnrollment $enrollment, EnrollmentStartCorrectionService $service): RedirectResponse
    {
        $this->ensureStudent($association, $student);
        abort_unless($enrollment->student_id === $student->id, 404);

        return back()->with(EnrollmentStartCorrectionService::flash($service->correct($association, $enrollment, $request->validated())));
    }

    public function activate(Association $association, Student $student, StudentService $service): RedirectResponse
    {
        $this->ensureStudent($association, $student);
        $service->activate($association, $student);

        return back()->with('status', 'Aluno ativado com sucesso.');
    }

    private function ensureStudent(Association $association, Student $student): void
    {
        abort_unless($student->association_id === $association->id, 404);
    }
}

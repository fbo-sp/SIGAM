<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\GuardianRequest;
use App\Models\Association;
use App\Models\Person;
use App\Models\Student;
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
        $guardianCandidates = Person::query()->whereHas('associations', fn ($query) => $query->whereKey($association->id))->whereKeyNot($student->person_id)->orderBy('full_name')->limit(200)->get();
        $historyFilters = ['year' => $request->filled('history_year') ? $request->integer('history_year') : null, 'martial_art_id' => $request->filled('history_martial_art_id') ? $request->integer('history_martial_art_id') : null];

        return view('sports.students.show', compact('association', 'student', 'guardianCandidates', 'historyFilters'));
    }

    public function addGuardian(GuardianRequest $request, Association $association, Student $student, StudentService $service): RedirectResponse
    {
        $this->ensureStudent($association, $student);
        $service->addGuardian($association, $student, $request->validated());

        return back()->with('status', 'Responsável vinculado ao aluno.');
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

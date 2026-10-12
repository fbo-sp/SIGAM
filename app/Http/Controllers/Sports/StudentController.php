<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\GuardianRequest;
use App\Http\Requests\Sports\StudentRequest;
use App\Models\Association;
use App\Models\MartialArt;
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

    public function create(Association $association): View
    {
        return view('sports.students.create', $this->formData($association));
    }

    public function store(StudentRequest $request, Association $association, StudentService $service): RedirectResponse
    {
        $student = $service->create($association, $request->validated());

        return redirect()->route('associations.students.show', [$association, $student])->with('status', 'Pré-cadastro do aluno criado. Revise os responsáveis antes de ativar.');
    }

    public function show(Request $request, Association $association, Student $student): View
    {
        $this->ensureStudent($association, $student);
        $student->load(['person', 'primaryDojo', 'guardians.person', 'martialArts.martialArt', 'martialArts.currentRank', 'martialArts.rankHistory.rank', 'federationMemberships.federation', 'federationMemberships.martialArt', 'federationMemberships.rank', 'championshipRegistrations.championship', 'championshipRegistrations.result', 'championshipRegistrations.charge']);
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

    private function formData(Association $association): array
    {
        return ['association' => $association, 'people' => Person::query()->whereHas('associations', fn ($query) => $query->whereKey($association->id))->whereDoesntHave('student', fn ($query) => $query->where('association_id', $association->id))->orderBy('full_name')->limit(200)->get(), 'dojos' => $association->dojos()->where('status', 'active')->orderBy('name')->get(), 'martialArts' => MartialArt::query()->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->where('status', 'active')->with('ranks')->orderBy('name')->get()];
    }

    private function ensureStudent(Association $association, Student $student): void
    {
        abort_unless($student->association_id === $association->id, 404);
    }
}

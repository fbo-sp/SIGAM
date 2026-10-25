<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progression\AssessmentRequest;
use App\Http\Requests\Progression\PanelFilterRequest;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\QuizRelease;
use App\Models\Student;
use App\Services\Access\OperationalScope;
use App\Services\Progression\AssessmentService;
use App\Services\Progression\CurriculumCoverageService;
use App\Services\Progression\ProgressionPanelService;
use App\Services\Progression\QuizService;
use App\Services\Progression\SessionTechniqueService;
use App\Services\Progression\StudentProgressService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Acompanhamento técnico do aluno no contexto do dojo (ADR-006 e ADR-014). */
class DojoProgressionController extends Controller
{
    public function __construct(private readonly OperationalScope $scope, private readonly AssessmentService $assessments, private readonly StudentProgressService $progress) {}

    public function show(Request $request, Dojo $dojo, Student $student): View
    {
        [$martialArt, $options] = $this->resolve($request, $dojo, $student);
        abort_if($martialArt === null, 404);
        abort_unless($this->assessments->canViewInDojo($request->user(), $dojo, $student, $martialArt), 403);
        $student->load('person');

        return view('progression.student', [
            'student' => $student,
            'martialArt' => $martialArt,
            'options' => $options,
            'snapshot' => $this->progress->snapshot($dojo->association, $student, $martialArt),
            'history' => $this->assessments->history($student, $martialArt),
            'worked' => $this->progress->worked($dojo->association, $student, $martialArt, today()->subDays(90), today()),
            'levels' => AssessmentLevel::labels(),
            'dojo' => $dojo,
            'association' => null,
            'canAssess' => $this->assessments->assessorTeacherId($request->user(), $dojo, $student, $martialArt) !== null,
            'switchUrl' => fn (int $id) => route('operation.progression.show', [$dojo, $student, 'modalidade' => $id]),
            ...app(QuizService::class)->summary($student, $martialArt),
        ]);
    }

    public function edit(Request $request, Dojo $dojo, Student $student, MartialArt $martialArt): View
    {
        $this->enter($request, $dojo);
        abort_if($this->assessments->assessorTeacherId($request->user(), $dojo, $student, $martialArt) === null, 403);
        $student->load('person');

        return view('progression.assess', [
            'dojo' => $dojo,
            'student' => $student,
            'martialArt' => $martialArt,
            'snapshot' => $this->progress->snapshot($dojo->association, $student, $martialArt),
            'levels' => AssessmentLevel::labels(),
        ]);
    }

    public function store(AssessmentRequest $request, Dojo $dojo, Student $student, MartialArt $martialArt): RedirectResponse
    {
        $this->enter($request, $dojo);
        $data = $request->validated();
        $count = $this->assessments->record($request->user(), $dojo, $student, $martialArt, $data['levels'] ?? [], Carbon::parse($data['assessed_on']), $data['notes'] ?? null);

        return redirect()->route('operation.progression.show', [$dojo, $student, 'modalidade' => $martialArt->id])
            ->with('status', $count === 0 ? 'Nenhum nível mudou; nada foi gravado.' : "Avaliação registrada em {$count} item(ns).");
    }

    public function saveTechniques(Request $request, Dojo $dojo, ClassSession $session, SessionTechniqueService $service): RedirectResponse
    {
        $this->enter($request, $dojo);
        abort_unless($this->scope->sessions($request->user(), $dojo)->whereKey($session->id)->exists(), 404);
        $data = $request->validate(['techniques' => ['nullable', 'array', 'max:500'], 'techniques.*' => ['integer']]);
        $result = $service->sync($request->user(), $dojo, $session, $data['techniques'] ?? []);

        return redirect()->route('operation.session', [$dojo, $session])->withFragment('tecnicas')
            ->with('status', $result['added'] + $result['removed'] === 0 ? 'Técnicas sem alteração.' : 'Técnicas trabalhadas salvas.');
    }

    public function panel(PanelFilterRequest $request, Dojo $dojo, ProgressionPanelService $panels): View
    {
        $this->enter($request, $dojo);
        $user = $request->user();
        abort_unless($this->scope->canDojo($user, $dojo, 'progression.report') || $this->scope->canTeacher($user, $dojo, 'progression.report'), 403);
        $groups = $panels->dojoClasses($user, $dojo)->with('martialArt')->orderBy('name')->get();
        $martialArts = $groups->pluck('martialArt')->unique('id')->sortBy('name')->values();
        $martialArt = $martialArts->firstWhere('id', $request->integer('modalidade')) ?? $martialArts->first();
        $classes = $martialArt ? $groups->where('martial_art_id', $martialArt->id)->values() : collect();
        $selected = $request->filled('turma') ? $classes->firstWhere('id', $request->integer('turma')) : null;
        abort_if($request->filled('turma') && $selected === null, 404);
        [$from, $until] = $request->period();

        return view('progression.panel', [
            'dojo' => $dojo,
            'martialArts' => $martialArts,
            'martialArt' => $martialArt,
            'classes' => $classes,
            'selected' => $selected,
            'from' => $from,
            'until' => $until,
            'panel' => $martialArt && $classes->isNotEmpty() ? $panels->panel($dojo->association, $martialArt, $selected ? collect([$selected]) : $classes, $from, $until) : null,
        ]);
    }

    /** Cobertura do currículo da turma: visão geral e por faixa buscada pelos alunos. */
    public function coverage(PanelFilterRequest $request, Dojo $dojo, ClassGroup $classGroup, ProgressionPanelService $panels, CurriculumCoverageService $coverage): View
    {
        $this->enter($request, $dojo);
        abort_unless($classGroup->association_id === $dojo->association_id && $classGroup->dojo_id === $dojo->id, 404);
        abort_unless($panels->dojoClasses($request->user(), $dojo)->whereKey($classGroup->id)->exists(), 403);
        [$from, $until] = $request->period();
        $classGroup->load('martialArt');

        return view('progression.coverage', [
            'dojo' => $dojo,
            'classGroup' => $classGroup,
            'from' => $from,
            'until' => $until,
            'coverage' => $coverage->forClass($dojo->association, $classGroup, $from, $until),
        ]);
    }

    public function releaseQuiz(Request $request, Dojo $dojo, Student $student, MartialArt $martialArt, QuizService $quiz): RedirectResponse
    {
        $this->enter($request, $dojo);
        $release = $quiz->release($request->user(), $dojo, $student, $martialArt);

        return redirect()->route('operation.progression.show', [$dojo, $student, 'modalidade' => $martialArt->id])
            ->with('status', 'Prova oficial do quiz liberada para '.$release->rank->display_name.'. O aluno faz pelo portal.');
    }

    public function cancelQuiz(Request $request, Dojo $dojo, Student $student, QuizRelease $release, QuizService $quiz): RedirectResponse
    {
        $this->enter($request, $dojo);
        $quiz->cancelRelease($request->user(), $dojo, $student, $release);

        return back()->with('status', 'Liberação da prova oficial cancelada.');
    }

    /** @return array{0: ?MartialArt, 1: Collection} */
    private function resolve(Request $request, Dojo $dojo, Student $student): array
    {
        $this->enter($request, $dojo);
        abort_unless($student->association_id === $dojo->association_id, 404);
        $options = $this->progress->martialArts($student);
        $link = $options->firstWhere('martial_art_id', $request->integer('modalidade')) ?? $options->first();

        return [$link?->martialArt, $options];
    }

    private function enter(Request $request, Dojo $dojo): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}

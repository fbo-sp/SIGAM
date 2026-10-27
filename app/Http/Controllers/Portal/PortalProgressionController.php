<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\MartialArt;
use App\Models\QuizAttempt;
use App\Models\QuizRelease;
use App\Models\Student;
use App\Services\Portal\PortalAccessService;
use App\Services\Progression\AssessmentService;
use App\Services\Progression\QuizService;
use App\Services\Progression\StudentProgressService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Progresso técnico e quiz no portal: o próprio aluno ou o responsável, só dos dependentes (ADR-014). */
class PortalProgressionController extends Controller
{
    /** Subtelas abertas pelos atalhos abaixo do Perfil técnico. */
    public const PARTS = ['quiz' => 'Quiz teórico', 'falta' => 'O que falta', 'aulas' => 'Nas aulas', 'evolucao' => 'Evolução'];

    public function __construct(private readonly PortalAccessService $access, private readonly StudentProgressService $progress, private readonly QuizService $quiz, private readonly AssessmentService $assessments) {}

    public function show(Request $request, Student $student, ?string $part = null): View
    {
        $student = $this->access->resolveStudent($request->user(), $student);
        $options = $this->progress->martialArts($student);
        $link = $options->firstWhere('martial_art_id', $request->integer('modalidade')) ?? $options->first();
        abort_if($link === null, 404);
        $martialArt = $link->martialArt;
        $student->load('person');
        $snapshot = $this->progress->snapshot($student->association, $student, $martialArt);

        return view('portal.progress', [
            'part' => $part,
            'student' => $student,
            'martialArt' => $martialArt,
            'options' => $options,
            'snapshot' => $snapshot,
            'timeline' => $part === 'evolucao' && $snapshot['next_rank'] ? $this->progress->timeline($student, $martialArt, $snapshot) : null,
            'worked' => $this->progress->worked($student->association, $student, $martialArt, today()->subDays(90), today()),
            'history' => $this->assessments->history($student, $martialArt, 30),
            'levels' => AssessmentLevel::labels(),
            'attempts' => $this->quiz->attempts($student, $martialArt),
            'inProgress' => QuizAttempt::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)->where('status', 'in_progress')->with('rank')->get(),
            ...$this->quiz->summary($student, $martialArt),
        ]);
    }

    public function start(Request $request, Student $student, MartialArt $martialArt): RedirectResponse
    {
        $student = $this->access->resolveStudent($request->user(), $student);
        abort_unless($this->progress->martialArts($student)->contains('martial_art_id', $martialArt->id), 404);
        $data = $request->validate(['mode' => ['required', 'in:training,official'], 'release' => ['required_if:mode,official', 'nullable', 'integer']]);
        $release = $data['mode'] === 'official' ? QuizRelease::query()->where('student_id', $student->id)->findOrFail($data['release']) : null;
        $attempt = $this->quiz->start($request->user(), $student, $martialArt, $data['mode'], $release);

        return redirect()->route('portal.quiz.attempt', [$student, $attempt]);
    }

    public function attempt(Request $request, Student $student, QuizAttempt $attempt): View
    {
        $student = $this->ensureAttempt($request, $student, $attempt);
        $attempt->load(['rank', 'martialArt', 'answers.question.options']);

        return view('portal.quiz', ['student' => $student->load('person'), 'attempt' => $attempt]);
    }

    public function submit(Request $request, Student $student, QuizAttempt $attempt): RedirectResponse
    {
        $student = $this->ensureAttempt($request, $student, $attempt);
        $data = $request->validate(['answers' => ['nullable', 'array'], 'answers.*' => ['nullable', 'integer']]);
        $attempt = $this->quiz->submit($request->user(), $attempt, $data['answers'] ?? []);

        return redirect()->route('portal.quiz.attempt', [$student, $attempt])->with('status', "Quiz finalizado: {$attempt->score}% de acertos.");
    }

    private function ensureAttempt(Request $request, Student $student, QuizAttempt $attempt): Student
    {
        $student = $this->access->resolveStudent($request->user(), $student);
        abort_unless($attempt->student_id === $student->id, 404);

        return $student;
    }
}

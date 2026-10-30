<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\ExamRequest;
use App\Models\Association;
use App\Models\Dojo;
use App\Models\Rank;
use App\Models\RankExam;
use App\Services\Exams\ExamContext;
use App\Services\Exams\ExamScope;
use App\Services\Exams\ExamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Exames de graduação nos três níveis (associação, dojo e banca do professor). Cada ação pública só monta o contexto
 * pelo nível da rota; o trabalho fica nas ações privadas e no ExamService.
 */
class ExamController extends Controller
{
    public function __construct(private readonly ExamScope $scope, private readonly ExamService $exams) {}

    public function associationIndex(Request $request, Association $association): View
    {
        return $this->index($request, $this->scope->association($request->user(), $association));
    }

    public function dojoIndex(Request $request, Dojo $dojo): View
    {
        return $this->index($request, $this->scope->dojo($request->user(), $dojo));
    }

    public function teacherIndex(Request $request): View
    {
        return $this->index($request, $this->scope->teacher($request->user()));
    }

    public function associationCreate(Request $request, Association $association): View
    {
        return $this->form($this->scope->association($request->user(), $association));
    }

    public function dojoCreate(Request $request, Dojo $dojo): View
    {
        return $this->form($this->scope->dojo($request->user(), $dojo));
    }

    public function associationStore(ExamRequest $request, Association $association): RedirectResponse
    {
        return $this->store($request, $this->scope->association($request->user(), $association));
    }

    public function dojoStore(ExamRequest $request, Dojo $dojo): RedirectResponse
    {
        return $this->store($request, $this->scope->dojo($request->user(), $dojo));
    }

    public function associationShow(Request $request, Association $association, RankExam $exam): View
    {
        return $this->show($this->scope->association($request->user(), $association), $exam);
    }

    public function dojoShow(Request $request, Dojo $dojo, RankExam $exam): View
    {
        return $this->show($this->scope->dojo($request->user(), $dojo), $exam);
    }

    public function teacherShow(Request $request, RankExam $exam): View
    {
        return $this->show($this->scope->teacher($request->user()), $exam);
    }

    public function associationEdit(Request $request, Association $association, RankExam $exam): View
    {
        return $this->form($this->scope->association($request->user(), $association), $exam);
    }

    public function dojoEdit(Request $request, Dojo $dojo, RankExam $exam): View
    {
        return $this->form($this->scope->dojo($request->user(), $dojo), $exam);
    }

    public function associationUpdate(ExamRequest $request, Association $association, RankExam $exam): RedirectResponse
    {
        return $this->update($request, $this->scope->association($request->user(), $association), $exam);
    }

    public function dojoUpdate(ExamRequest $request, Dojo $dojo, RankExam $exam): RedirectResponse
    {
        return $this->update($request, $this->scope->dojo($request->user(), $dojo), $exam);
    }

    public function associationParticipants(Request $request, Association $association, RankExam $exam): View
    {
        return $this->participants($this->scope->association($request->user(), $association), $exam);
    }

    public function dojoParticipants(Request $request, Dojo $dojo, RankExam $exam): View
    {
        return $this->participants($this->scope->dojo($request->user(), $dojo), $exam);
    }

    public function associationParticipantsSave(Request $request, Association $association, RankExam $exam): RedirectResponse
    {
        return $this->participantsSave($request, $this->scope->association($request->user(), $association), $exam);
    }

    public function dojoParticipantsSave(Request $request, Dojo $dojo, RankExam $exam): RedirectResponse
    {
        return $this->participantsSave($request, $this->scope->dojo($request->user(), $dojo), $exam);
    }

    public function associationContent(Request $request, Association $association, RankExam $exam, ?Rank $rank = null): View|RedirectResponse
    {
        return $this->content($this->scope->association($request->user(), $association), $exam, $rank);
    }

    public function dojoContent(Request $request, Dojo $dojo, RankExam $exam, ?Rank $rank = null): View|RedirectResponse
    {
        return $this->content($this->scope->dojo($request->user(), $dojo), $exam, $rank);
    }

    public function teacherContent(Request $request, RankExam $exam, ?Rank $rank = null): View|RedirectResponse
    {
        return $this->content($this->scope->teacher($request->user()), $exam, $rank);
    }

    public function associationContentSave(Request $request, Association $association, RankExam $exam, Rank $rank): RedirectResponse
    {
        return $this->contentSave($request, $this->scope->association($request->user(), $association), $exam, $rank);
    }

    public function dojoContentSave(Request $request, Dojo $dojo, RankExam $exam, Rank $rank): RedirectResponse
    {
        return $this->contentSave($request, $this->scope->dojo($request->user(), $dojo), $exam, $rank);
    }

    public function teacherContentSave(Request $request, RankExam $exam, Rank $rank): RedirectResponse
    {
        return $this->contentSave($request, $this->scope->teacher($request->user()), $exam, $rank);
    }

    public function associationGroup(Request $request, Association $association, RankExam $exam, Rank $rank): RedirectResponse
    {
        return $this->group($request, $this->scope->association($request->user(), $association), $exam, $rank);
    }

    public function dojoGroup(Request $request, Dojo $dojo, RankExam $exam, Rank $rank): RedirectResponse
    {
        return $this->group($request, $this->scope->dojo($request->user(), $dojo), $exam, $rank);
    }

    public function associationOpen(Request $request, Association $association, RankExam $exam): RedirectResponse
    {
        return $this->open($this->scope->association($request->user(), $association), $exam);
    }

    public function dojoOpen(Request $request, Dojo $dojo, RankExam $exam): RedirectResponse
    {
        return $this->open($this->scope->dojo($request->user(), $dojo), $exam);
    }

    public function associationCancel(Request $request, Association $association, RankExam $exam): RedirectResponse
    {
        return $this->cancel($request, $this->scope->association($request->user(), $association), $exam);
    }

    public function dojoCancel(Request $request, Dojo $dojo, RankExam $exam): RedirectResponse
    {
        return $this->cancel($request, $this->scope->dojo($request->user(), $dojo), $exam);
    }

    private function index(Request $request, ExamContext $context): View
    {
        $tab = in_array($request->query('aba'), ['abertos', 'rascunhos', 'encerrados'], true) ? $request->query('aba') : 'abertos';
        $status = ['abertos' => ['open'], 'rascunhos' => ['draft'], 'encerrados' => ['closed', 'cancelled']][$tab];
        $counts = $context->exams()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $exams = $context->exams()->whereIn('status', $status)->with(['dojo:id,name', 'classGroup:id,name', 'martialArt:id,name', 'student.person:id,full_name,social_name'])
            ->withCount('enrolled')->orderByDesc('starts_on')->orderByDesc('id')->limit(100)->get();

        return view('exams.index', compact('context', 'exams', 'tab', 'counts'));
    }

    private function form(ExamContext $context, ?RankExam $exam = null): View
    {
        abort_unless($context->canManage(), 403);
        if ($exam) {
            $this->scope->exam($context, $exam);
            abort_unless($exam->isEditable(), 404);
            $exam->load('examiners:id');
        }

        return view('exams.form', ['context' => $context, 'exam' => $exam, 'locked' => $exam?->participants()->exists() ?? false, ...$this->exams->formOptions($context)]);
    }

    private function store(ExamRequest $request, ExamContext $context): RedirectResponse
    {
        $exam = $this->exams->create($context, $request->validated());

        return redirect($context->route('participants', ['exam' => $exam]))->with('status', 'Exame criado como rascunho. Agora escolha os participantes.');
    }

    private function update(ExamRequest $request, ExamContext $context, RankExam $exam): RedirectResponse
    {
        $this->exams->update($context, $this->scope->exam($context, $exam), $request->validated());

        return redirect($context->route('show', ['exam' => $exam]))->with('status', 'Dados do exame atualizados.');
    }

    private function show(ExamContext $context, RankExam $exam): View
    {
        $this->scope->exam($context, $exam)->load(['martialArt', 'dojo', 'classGroup', 'student.person', 'examiners.person']);
        $ranks = $this->exams->ranks($exam);
        $participants = $exam->enrolled()->with(['student.person:id,full_name,social_name,photo_path', 'currentRank', 'targetRank'])->get()
            ->sortBy(fn ($participant) => [$participant->targetRank->sort_order, mb_strtolower($participant->student->person->full_name)])->values();
        $problems = $exam->status === 'draft' && $context->canManage() ? $this->exams->openProblems($exam) : [];

        return view('exams.show', compact('context', 'exam', 'ranks', 'participants', 'problems'));
    }

    private function participants(ExamContext $context, RankExam $exam): View
    {
        $this->scope->exam($context, $exam)->load('martialArt', 'dojo', 'classGroup');
        abort_unless($context->canManage() && $exam->isEditable(), 404);
        $rows = $this->exams->participantRows($exam);
        $fresh = ! $exam->participants()->exists();

        return view('exams.participants', compact('context', 'exam', 'rows', 'fresh'));
    }

    private function participantsSave(Request $request, ExamContext $context, RankExam $exam): RedirectResponse
    {
        $data = $request->validate(['students' => ['array'], 'students.*.include' => ['nullable', 'boolean'], 'students.*.reason' => ['nullable', 'string', 'max:1000']]);
        $result = $this->exams->saveParticipants($context, $this->scope->exam($context, $exam), $data['students'] ?? []);

        return redirect($context->route('content', ['exam' => $exam]))
            ->with('status', "Participantes salvos: {$result['enrolled']} aluno(s) no exame. Confira o conteúdo de cada faixa.");
    }

    private function content(ExamContext $context, RankExam $exam, ?Rank $rank): View|RedirectResponse
    {
        $this->scope->exam($context, $exam)->load('martialArt', 'dojo', 'classGroup');
        $ranks = $this->exams->ranks($exam);
        if ($ranks->isEmpty()) {
            return redirect($context->canManage() && $exam->isEditable() ? $context->route('participants', ['exam' => $exam]) : $context->route('show', ['exam' => $exam]))
                ->with('status', 'Inclua participantes para montar o conteúdo.');
        }
        $rank = $rank && $ranks->contains(fn (array $row) => $row['rank']->id === $rank->id) ? $rank : $ranks->first()['rank'];
        $content = $this->exams->content($exam, $rank);
        $editable = $exam->isEditable();

        return view('exams.content', compact('context', 'exam', 'ranks', 'rank', 'content', 'editable'));
    }

    private function contentSave(Request $request, ExamContext $context, RankExam $exam, Rank $rank): RedirectResponse
    {
        $data = $request->validate(['items' => ['array'], 'items.*.selected' => ['nullable', 'boolean'], 'items.*.justification' => ['nullable', 'string', 'max:500'], 'items.*.group' => ['nullable', 'integer']]);
        $changed = $this->exams->saveContent($context, $this->scope->exam($context, $exam), $rank, $data['items'] ?? []);

        return redirect($context->route('content', ['exam' => $exam, 'rank' => $rank]))->with('status', $changed ? 'Conteúdo da faixa salvo.' : 'Nada mudou no conteúdo.');
    }

    private function group(Request $request, ExamContext $context, RankExam $exam, Rank $rank): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']], ['name.required' => 'Escreva o nome do grupo.']);
        $this->exams->addGroup($context, $this->scope->exam($context, $exam), $rank, $data['name']);

        return redirect($context->route('content', ['exam' => $exam, 'rank' => $rank]))->with('status', 'Grupo criado. Mova os itens para ele e salve.');
    }

    private function open(ExamContext $context, RankExam $exam): RedirectResponse
    {
        $this->exams->open($context, $this->scope->exam($context, $exam));

        return redirect($context->route('show', ['exam' => $exam]))->with('status', 'Exame aberto. A banca já pode avaliar no período do exame.');
    }

    private function cancel(Request $request, ExamContext $context, RankExam $exam): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Escreva o motivo do cancelamento.', 'reason.min' => 'Escreva o motivo do cancelamento.']);
        $this->exams->cancel($context, $this->scope->exam($context, $exam), $data['reason']);

        return redirect($context->route('index', ['aba' => 'encerrados']))->with('status', 'Exame cancelado. Ele continua guardado no histórico.');
    }
}

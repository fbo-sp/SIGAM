<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progression\ProgressionSettingsRequest;
use App\Http\Requests\Progression\QuizQuestionRequest;
use App\Http\Requests\Progression\TechniqueStatusRequest;
use App\Models\Association;
use App\Models\MartialArt;
use App\Models\ProgressionSetting;
use App\Models\QuizQuestion;
use App\Models\Rank;
use App\Services\Progression\QuizService;
use App\Services\Progression\TechniqueCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Banco de perguntas do quiz e parâmetros do acompanhamento, no contexto da associação. */
class QuizBankController extends Controller
{
    public function index(Request $request, Association $association, MartialArt $martialArt, TechniqueCatalogService $catalog): View
    {
        $this->ensureMartialArt($association, $martialArt);
        $ranks = Rank::query()->where('martial_art_id', $martialArt->id)->orderBy('sort_order')->get();
        $rank = $ranks->firstWhere('id', $request->integer('graduacao')) ?? $ranks->first();
        $counts = QuizQuestion::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)->where('status', 'active')
            ->selectRaw('rank_id, count(*) as total')->groupBy('rank_id')->pluck('total', 'rank_id');

        return view('progression.quiz-bank', [
            'association' => $association,
            'martialArt' => $martialArt,
            'ranks' => $ranks,
            'rank' => $rank,
            'counts' => $counts,
            'questions' => $rank ? QuizQuestion::query()->where('association_id', $association->id)->where('rank_id', $rank->id)
                ->with(['options', 'item'])->orderByRaw("case when status = 'active' then 0 else 1 end")->orderBy('id')->get() : collect(),
            'theoryItems' => $this->theoryItems($association, $martialArt, $catalog),
            'settings' => ProgressionSetting::for($association->id),
        ]);
    }

    public function store(QuizQuestionRequest $request, Association $association, MartialArt $martialArt, QuizService $quiz): RedirectResponse
    {
        $this->ensureMartialArt($association, $martialArt);
        $question = $quiz->saveQuestion($association, $martialArt, $request->validated(), $request->user());

        return redirect()->route('associations.quiz.index', [$association, $martialArt, 'graduacao' => $question->rank_id])->with('status', 'Pergunta incluída.');
    }

    public function edit(Association $association, MartialArt $martialArt, QuizQuestion $question, TechniqueCatalogService $catalog): View
    {
        $this->ensureQuestion($association, $martialArt, $question);

        return view('progression.quiz-question-edit', [
            'association' => $association,
            'martialArt' => $martialArt,
            'question' => $question->load('options'),
            'ranks' => Rank::query()->where('martial_art_id', $martialArt->id)->orderBy('sort_order')->get(),
            'theoryItems' => $this->theoryItems($association, $martialArt, $catalog),
        ]);
    }

    public function update(QuizQuestionRequest $request, Association $association, MartialArt $martialArt, QuizQuestion $question, QuizService $quiz): RedirectResponse
    {
        $this->ensureQuestion($association, $martialArt, $question);
        $quiz->saveQuestion($association, $martialArt, $request->validated(), $request->user(), $question);

        return redirect()->route('associations.quiz.index', [$association, $martialArt, 'graduacao' => $question->rank_id])->with('status', 'Pergunta atualizada.');
    }

    public function status(TechniqueStatusRequest $request, Association $association, MartialArt $martialArt, QuizQuestion $question, QuizService $quiz): RedirectResponse
    {
        $this->ensureQuestion($association, $martialArt, $question);
        $quiz->setQuestionStatus($association, $question, $request->validated('status'));

        return back()->with('status', $request->validated('status') === 'active' ? 'Pergunta reativada.' : 'Pergunta inativada. Ela não entra nos próximos sorteios.');
    }

    public function settings(Association $association): View
    {
        return view('progression.settings', ['association' => $association, 'settings' => ProgressionSetting::for($association->id)]);
    }

    public function saveSettings(ProgressionSettingsRequest $request, Association $association, QuizService $quiz): RedirectResponse
    {
        $quiz->saveSettings($association, [...$request->validated(), 'allow_group_pick' => $request->boolean('allow_group_pick')], $request->user());

        return back()->with('status', 'Configurações do acompanhamento salvas.');
    }

    private function theoryItems(Association $association, MartialArt $martialArt, TechniqueCatalogService $catalog): array
    {
        $items = $catalog->items($association, $martialArt);
        $paths = $catalog->paths($items);
        $inactive = $catalog->inactiveIds($items);

        return $items->where('knowledge_type', 'theoretical')->reject(fn ($item) => isset($inactive[$item->id]))
            ->mapWithKeys(fn ($item) => [$item->id => $paths[$item->id]])->sort()->all();
    }

    private function ensureMartialArt(Association $association, MartialArt $martialArt): void
    {
        abort_unless($martialArt->association_id === null || $martialArt->association_id === $association->id, 404);
    }

    private function ensureQuestion(Association $association, MartialArt $martialArt, QuizQuestion $question): void
    {
        $this->ensureMartialArt($association, $martialArt);
        abort_unless($question->association_id === $association->id && $question->martial_art_id === $martialArt->id, 404);
    }
}

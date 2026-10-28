<?php

namespace App\Http\Controllers\Classes;

use App\Http\Controllers\Controller;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\TechniqueItem;
use App\Services\Classes\TeacherWorkspaceService;
use App\Services\Progression\TechniqueStudyService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Estudar do professor (decisão de Fabio em 28/10/2026): consulta do mesmo material do portal, sem a situação de um
 * aluno e sem "Estudei". Vale para quem tem perfil de professor ativo, no catálogo da própria associação.
 */
class TeacherStudyController extends Controller
{
    public function __construct(private readonly TeacherWorkspaceService $workspace, private readonly TechniqueStudyService $study) {}

    public function index(Request $request): View
    {
        $options = $this->options($request);
        $martialArt = $options->firstWhere('id', $request->integer('modalidade')) ?? $options->first();
        abort_if($martialArt === null, 404);
        $rows = $this->study->reference($request->user()->association, $martialArt);
        $ranks = Rank::query()->where('martial_art_id', $martialArt->id)->whereIn('id', $rows->pluck('first_rank.id')->filter()->unique())->orderBy('sort_order')->get();
        $rank = $ranks->firstWhere('id', $request->integer('faixa'));
        $term = mb_substr(trim((string) $request->query('q')), 0, 80);
        $rows = $this->study->filter($rows, 'todas', $term)->when($rank && $term === '', fn (Collection $set) => $set->filter(fn (array $row) => $row['first_rank']?->id === $rank->id));

        return view('teacher.study.index', [
            'martialArt' => $martialArt, 'options' => $options, 'ranks' => $ranks, 'rank' => $term === '' ? $rank : null, 'term' => $term, 'groups' => $rows->groupBy('group'),
        ]);
    }

    public function show(Request $request, TechniqueItem $item): View
    {
        $options = $this->options($request);
        $martialArt = $options->firstWhere('id', $item->martial_art_id);
        abort_if($martialArt === null || $item->association_id !== $request->user()->association_id || $item->kind !== 'item' || $item->status !== 'active', 404);
        $rows = $this->study->reference($request->user()->association, $martialArt);
        $entry = $rows->first(fn (array $row) => $row['item']->id === $item->id);
        abort_if($entry === null, 404);

        return view('teacher.study.show', [
            'martialArt' => $martialArt, 'entry' => $entry, 'item' => $item, 'levels' => AssessmentLevel::labels(),
            'curriculum' => $this->study->curriculumFor($item),
            'related' => $rows->filter(fn (array $row) => $row['item']->parent_id === $item->parent_id && $row['item']->id !== $item->id)->take(4)->values(),
        ]);
    }

    /** @return Collection<int, MartialArt> modalidades ativas da associação com catálogo ativo */
    private function options(Request $request): Collection
    {
        $user = $request->user();
        abort_if($this->workspace->teacherId($user) === null, 403);

        return MartialArt::query()->where('association_id', $user->association_id)->where('status', 'active')
            ->whereIn('id', TechniqueItem::query()->select('martial_art_id')->where('association_id', $user->association_id)->where('kind', 'item')->where('status', 'active'))->orderBy('name')->get();
    }
}

<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progression\CurriculumEntryRequest;
use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\TechniqueItem;
use App\Services\Progression\CurriculumService;
use App\Services\Progression\TechniqueCatalogService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CurriculumController extends Controller
{
    public function show(Request $request, Association $association, MartialArt $martialArt, CurriculumService $curriculum, TechniqueCatalogService $catalog): View
    {
        $this->ensureMartialArt($association, $martialArt);
        $ranks = Rank::query()->where('martial_art_id', $martialArt->id)->orderBy('sort_order')->get();
        $rank = $ranks->firstWhere('id', (int) $request->query('graduacao')) ?? $ranks->first();
        $items = $catalog->items($association, $martialArt);
        $paths = $catalog->paths($items);
        $inactive = $catalog->inactiveIds($items);
        $effective = $rank ? $curriculum->effective($association, $rank) : ['rows' => [], 'removed' => []];

        return view('progression.curriculum', [
            'association' => $association,
            'martialArt' => $martialArt,
            'ranks' => $ranks,
            'rank' => $rank,
            'rows' => collect($effective['rows'])->groupBy(fn (array $row) => $row['item']->knowledge_type),
            'removed' => $effective['removed'],
            'own' => $rank ? $curriculum->ownEntries($association, $rank) : collect(),
            'options' => $items->reject(fn (TechniqueItem $item) => isset($inactive[$item->id]))->sortBy(fn (TechniqueItem $item) => $paths[$item->id])
                ->mapWithKeys(fn (TechniqueItem $item) => [$item->id => $paths[$item->id]]),
            'levels' => AssessmentLevel::requirable(),
            'canManage' => $request->user()->hasPermission('curriculum.manage', $association->id),
        ]);
    }

    public function store(CurriculumEntryRequest $request, Association $association, MartialArt $martialArt, Rank $rank, CurriculumService $curriculum): RedirectResponse
    {
        $this->ensureRank($association, $martialArt, $rank);
        $item = TechniqueItem::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)->find($request->validated('technique_item_id'));
        abort_if($item === null, 404);
        $curriculum->set($association, $rank, $item, $request->level(), $request->user());

        return $this->back($association, $martialArt, $rank, $request->level() === null ? 'Item deixa de ser cobrado a partir desta graduação.' : 'Exigência salva.');
    }

    public function restore(Association $association, MartialArt $martialArt, Rank $rank, TechniqueItem $item, CurriculumService $curriculum): RedirectResponse
    {
        $this->ensureRank($association, $martialArt, $rank);
        $curriculum->restore($association, $rank, $item);

        return $this->back($association, $martialArt, $rank, 'Ajuste desfeito. O item volta a seguir as graduações anteriores.');
    }

    private function back(Association $association, MartialArt $martialArt, Rank $rank, string $message): RedirectResponse
    {
        return redirect()->route('associations.curriculum.show', [$association, $martialArt, 'graduacao' => $rank->id])->with('status', $message);
    }

    private function ensureMartialArt(Association $association, MartialArt $martialArt): void
    {
        abort_unless($martialArt->association_id === null || $martialArt->association_id === $association->id, 404);
    }

    private function ensureRank(Association $association, MartialArt $martialArt, Rank $rank): void
    {
        $this->ensureMartialArt($association, $martialArt);
        abort_unless($rank->martial_art_id === $martialArt->id, 404);
    }
}

<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\MartialArtRequest;
use App\Http\Requests\Sports\RankRequest;
use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Services\Sports\CatalogService;
use App\Support\Sports\RankTemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Association $association, RankTemplateCatalog $templates): View
    {
        return view('sports.catalog.index', [
            'association' => $association,
            'martialArts' => MartialArt::query()->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->with('ranks')->orderBy('name')->get(),
            'rankTemplates' => $templates->all(),
        ]);
    }

    public function storeMartialArt(MartialArtRequest $request, Association $association, CatalogService $service): RedirectResponse
    {
        $data = $request->validated();
        $templateKey = $data['template_key'] ?? null;
        unset($data['template_key']);
        $martialArt = $service->createMartialArt($association, $data, $templateKey);

        return back()->with('status', $templateKey === null
            ? 'Modalidade cadastrada.'
            : "Modalidade cadastrada com {$martialArt->ranks->count()} graduações do template.");
    }

    public function updateMartialArt(MartialArtRequest $request, Association $association, MartialArt $martialArt, CatalogService $service): RedirectResponse
    {
        abort_unless($martialArt->association_id === $association->id, 404);
        $service->saveMartialArt($association, $request->validated(), $martialArt);

        return back()->with('status', 'Modalidade atualizada.');
    }

    public function storeRank(RankRequest $request, Association $association, MartialArt $martialArt, CatalogService $service): RedirectResponse
    {
        $this->ensureMartialArt($association, $martialArt);
        $service->saveRank($martialArt, $request->validated());

        return back()->with('status', 'Graduação cadastrada.');
    }

    public function updateRank(RankRequest $request, Association $association, MartialArt $martialArt, Rank $rank, CatalogService $service): RedirectResponse
    {
        $this->ensureMartialArt($association, $martialArt);
        abort_unless($rank->martial_art_id === $martialArt->id, 404);
        $service->saveRank($martialArt, $request->validated(), $rank);

        return back()->with('status', 'Graduação atualizada.');
    }

    private function ensureMartialArt(Association $association, MartialArt $martialArt): void
    {
        abort_unless($martialArt->association_id === null || $martialArt->association_id === $association->id, 404);
    }
}

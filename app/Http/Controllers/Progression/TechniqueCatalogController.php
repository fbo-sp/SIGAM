<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progression\TechniqueItemRequest;
use App\Http\Requests\Progression\TechniqueStatusRequest;
use App\Http\Requests\Progression\TechniqueTemplateRequest;
use App\Models\Association;
use App\Models\MartialArt;
use App\Models\TechniqueItem;
use App\Services\Progression\TechniqueCatalogService;
use App\Services\Progression\TechniqueStudyService;
use App\Support\Progression\TechniqueTemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TechniqueCatalogController extends Controller
{
    public function index(Association $association, MartialArt $martialArt, TechniqueCatalogService $service, TechniqueTemplateCatalog $templates): View
    {
        $this->ensureMartialArt($association, $martialArt);
        $items = $service->items($association, $martialArt);
        $paths = $service->paths($items);

        return view('progression.catalog', [
            'association' => $association,
            'martialArt' => $martialArt,
            'trees' => collect($service->tree($items))->groupBy(fn (array $node) => $node['item']->knowledge_type),
            'within' => $items->where('kind', 'group')->firstWhere('id', request()->integer('dentro'))?->id,
            'inactive' => $service->inactiveIds($items),
            'groups' => $items->where('kind', 'group')->where('status', 'active')->sortBy(fn (TechniqueItem $item) => $paths[$item->id])->mapWithKeys(fn (TechniqueItem $item) => [$item->id => $paths[$item->id]]),
            'templates' => $templates->all(),
            'isEmpty' => $items->isEmpty(),
            'canManage' => request()->user()->hasPermission('curriculum.manage', $association->id),
        ]);
    }

    public function store(TechniqueItemRequest $request, Association $association, MartialArt $martialArt, TechniqueCatalogService $service): RedirectResponse
    {
        $this->ensureMartialArt($association, $martialArt);
        $item = $service->save($association, $martialArt, $request->validated());

        return back()->with('status', ($item->kind === 'group' ? 'Grupo' : 'Item').' incluído no catálogo.');
    }

    public function edit(Association $association, MartialArt $martialArt, TechniqueItem $item, TechniqueCatalogService $service): View
    {
        $this->ensureItem($association, $martialArt, $item);
        $items = $service->items($association, $martialArt);
        $paths = $service->paths($items);

        return view('progression.item-edit', [
            'association' => $association,
            'martialArt' => $martialArt,
            'item' => $item,
            'path' => $paths[$item->id],
            'hasChildren' => $items->contains('parent_id', $item->id),
            'groups' => $items->where('kind', 'group')->where('id', '!=', $item->id)->filter(fn (TechniqueItem $group) => ! str_starts_with($paths[$group->id].' > ', $paths[$item->id].' > '))
                ->sortBy(fn (TechniqueItem $group) => $paths[$group->id])->mapWithKeys(fn (TechniqueItem $group) => [$group->id => $paths[$group->id]]),
        ]);
    }

    public function update(TechniqueItemRequest $request, Association $association, MartialArt $martialArt, TechniqueItem $item, TechniqueCatalogService $service, TechniqueStudyService $study): RedirectResponse
    {
        $this->ensureItem($association, $martialArt, $item);
        $item = $service->save($association, $martialArt, $request->safe()->except(['sheet', 'remove_sheet']), $item);
        if ($request->boolean('remove_sheet')) {
            $study->removeSheet($association, $item);
        }
        if ($request->hasFile('sheet')) {
            $study->storeSheet($association, $item, $request->file('sheet'));
        }

        return redirect()->route('associations.techniques.index', [$association, $martialArt])->with('status', 'Item do catálogo atualizado.');
    }

    public function status(TechniqueStatusRequest $request, Association $association, MartialArt $martialArt, TechniqueItem $item, TechniqueCatalogService $service): RedirectResponse
    {
        $this->ensureItem($association, $martialArt, $item);
        $service->setStatus($association, $item, $request->validated('status'));

        return back()->with('status', $request->validated('status') === 'active' ? 'Item reativado.' : 'Item inativado. Ele deixa de aparecer no currículo, mas nada foi apagado.');
    }

    public function applyTemplate(TechniqueTemplateRequest $request, Association $association, MartialArt $martialArt, TechniqueCatalogService $service): RedirectResponse
    {
        $this->ensureMartialArt($association, $martialArt);
        $result = $service->applyTemplate($association, $martialArt, $request->validated('template_key'), $request->user(), $request->boolean('update_curriculum'));
        $message = "Modelo aplicado: {$result['items_created']} item(ns), {$result['entries_created']} exigência(s) de currículo e {$result['questions_created']} pergunta(s) de quiz incluídos; {$result['study_filled']} item(ns) receberam material de estudo.";
        if ($request->boolean('update_curriculum')) {
            $message .= " Currículo do modelo atualizado: {$result['entries_updated']} exigência(s) alterada(s) e {$result['entries_removed']} retirada(s); {$result['entries_kept']} ajuste(s) da associação mantido(s).";
        }
        if ($result['missing_ranks'] !== []) {
            $message .= ' Graduações não encontradas nesta modalidade: '.implode(', ', $result['missing_ranks']).'.';
        }

        return back()->with('status', $message);
    }

    private function ensureMartialArt(Association $association, MartialArt $martialArt): void
    {
        abort_unless($martialArt->association_id === null || $martialArt->association_id === $association->id, 404);
    }

    private function ensureItem(Association $association, MartialArt $martialArt, TechniqueItem $item): void
    {
        $this->ensureMartialArt($association, $martialArt);
        abort_unless($item->association_id === $association->id && $item->martial_art_id === $martialArt->id, 404);
    }
}

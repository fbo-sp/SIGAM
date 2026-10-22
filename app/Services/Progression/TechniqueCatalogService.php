<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\RankCurriculumEntry;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Progression\TechniqueTemplateCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Catálogo de técnicas em árvore por associação e modalidade (ADR-014). */
class TechniqueCatalogService
{
    public function __construct(private readonly AuditService $audit, private readonly TechniqueTemplateCatalog $templates) {}

    /** @return Collection<int, TechniqueItem> todos os itens da modalidade na associação, ativos e inativos */
    public function items(Association $association, MartialArt $martialArt): Collection
    {
        return TechniqueItem::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Caminho legível de cada item ("Nage-waza > Tachi-waza > Te-waza").
     *
     * @param  Collection<int, TechniqueItem>  $items
     * @return array<int, string>
     */
    public function paths(Collection $items): array
    {
        $byId = $items->keyBy('id');
        $paths = [];
        $resolve = function (TechniqueItem $item) use (&$resolve, &$paths, $byId): string {
            if (isset($paths[$item->id])) {
                return $paths[$item->id];
            }
            $parent = $item->parent_id ? $byId->get($item->parent_id) : null;

            return $paths[$item->id] = $parent ? $resolve($parent).' > '.$item->name : $item->name;
        };
        $items->each(fn (TechniqueItem $item) => $resolve($item));

        return $paths;
    }

    /**
     * Árvore achatada em ordem de leitura, com a profundidade de cada nó.
     *
     * @param  Collection<int, TechniqueItem>  $items
     * @return list<array{item: TechniqueItem, depth: int}>
     */
    public function flatten(Collection $items): array
    {
        $children = $items->groupBy(fn (TechniqueItem $item) => (int) $item->parent_id);
        $rows = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $children): void {
            foreach ($children->get($parentId, collect()) as $item) {
                $rows[] = ['item' => $item, 'depth' => $depth];
                $walk($item->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }

    /** Itens cujo próprio status ou de algum ancestral é inativo. @return array<int, true> */
    public function inactiveIds(Collection $items): array
    {
        $byId = $items->keyBy('id');
        $inactive = [];
        foreach ($items as $item) {
            for ($node = $item; $node; $node = $node->parent_id ? $byId->get($node->parent_id) : null) {
                if ($node->status !== 'active') {
                    $inactive[$item->id] = true;
                    break;
                }
            }
        }

        return $inactive;
    }

    public function save(Association $association, MartialArt $martialArt, array $data, ?TechniqueItem $item = null): TechniqueItem
    {
        return DB::transaction(function () use ($association, $martialArt, $data, $item): TechniqueItem {
            $items = TechniqueItem::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)->lockForUpdate()->get();
            $parent = isset($data['parent_id']) ? $items->firstWhere('id', (int) $data['parent_id']) : null;
            if (isset($data['parent_id']) && $parent === null) {
                throw ValidationException::withMessages(['parent_id' => 'Escolha um grupo desta modalidade.']);
            }
            if ($parent && $parent->kind !== 'group') {
                throw ValidationException::withMessages(['parent_id' => 'Uma técnica só pode ficar dentro de um grupo.']);
            }
            if ($item && $parent && ($parent->id === $item->id || in_array($parent->id, $this->descendantIds($items, $item->id), true))) {
                throw ValidationException::withMessages(['parent_id' => 'Um grupo não pode ficar dentro dele mesmo.']);
            }
            if ($item && $data['kind'] === 'item' && $items->contains('parent_id', $item->id)) {
                throw ValidationException::withMessages(['kind' => 'Este grupo tem itens dentro. Mova os itens antes de transformá-lo em técnica.']);
            }
            $name = trim($data['name']);
            if ($items->contains(fn (TechniqueItem $other) => $other->id !== $item?->id && $other->parent_id === $parent?->id && mb_strtolower($other->name) === mb_strtolower($name))) {
                throw ValidationException::withMessages(['name' => 'Já existe um item com este nome no mesmo grupo.']);
            }
            // O tipo de conhecimento vem do grupo raiz; itens internos herdam do grupo em que estão.
            $knowledge = $parent?->knowledge_type ?? $data['knowledge_type'];
            $before = $item?->only(['parent_id', 'kind', 'knowledge_type', 'name', 'status']);
            $item ??= new TechniqueItem(['association_id' => $association->id, 'martial_art_id' => $martialArt->id, 'status' => 'active']);
            $item->fill([
                'parent_id' => $parent?->id, 'kind' => $data['kind'], 'knowledge_type' => $knowledge, 'name' => $name,
                'original_name' => $data['original_name'] ?? null, 'description' => $data['description'] ?? null,
                'video_url' => $data['video_url'] ?? null, 'sort_order' => (int) ($data['sort_order'] ?? 0),
            ])->save();
            if ($before !== null && $before['knowledge_type'] !== $knowledge) {
                TechniqueItem::query()->whereKey($this->descendantIds($items, $item->id))->update(['knowledge_type' => $knowledge]);
            }
            $this->audit->record('technique_item.saved', $before === null ? 'created' : 'updated', $item, $before, $item->only(['parent_id', 'kind', 'knowledge_type', 'name', 'status']), ['association_id' => $association->id]);

            return $item;
        });
    }

    /** Inativar um grupo inativa também o que está dentro; nada é apagado. */
    public function setStatus(Association $association, TechniqueItem $item, string $status): void
    {
        DB::transaction(function () use ($association, $item, $status): void {
            $locked = TechniqueItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($status === 'active' && $locked->parent_id && TechniqueItem::query()->whereKey($locked->parent_id)->value('status') !== 'active') {
                throw ValidationException::withMessages(['status' => 'Reative primeiro o grupo em que este item está.']);
            }
            $ids = $status === 'inactive'
                ? [$locked->id, ...$this->descendantIds(TechniqueItem::query()->where('association_id', $locked->association_id)->where('martial_art_id', $locked->martial_art_id)->get(), $locked->id)]
                : [$locked->id];
            TechniqueItem::query()->whereKey($ids)->update(['status' => $status]);
            $this->audit->record('technique_item.status_changed', 'updated', $locked, ['status' => $locked->status], ['status' => $status, 'affected_items' => count($ids)], ['association_id' => $association->id]);
        });
    }

    /**
     * Copia o modelo para a associação. Repetir não duplica itens nem sobrescreve ajustes do currículo.
     *
     * @return array{items_created: int, entries_created: int, missing_ranks: list<string>}
     */
    public function applyTemplate(Association $association, MartialArt $martialArt, string $key, User $actor): array
    {
        $template = $this->templates->find($key);
        if ($template === null) {
            throw ValidationException::withMessages(['template_key' => 'Selecione um modelo válido.']);
        }

        return DB::transaction(function () use ($association, $martialArt, $key, $template, $actor): array {
            $items = TechniqueItem::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)->lockForUpdate()->get();
            $byPath = collect($this->paths($items))->flip()->map(fn (int $id) => $items->firstWhere('id', $id));
            $created = 0;
            $create = function (array $nodes, ?TechniqueItem $parent, string $prefix, ?string $knowledge) use (&$create, &$created, $association, $martialArt, $byPath): void {
                foreach (array_values($nodes) as $index => $node) {
                    $path = $prefix === '' ? $node['name'] : $prefix.' > '.$node['name'];
                    $nodeKnowledge = $knowledge ?? $node['knowledge'];
                    $item = $byPath->get($path);
                    if ($item === null) {
                        $item = TechniqueItem::query()->create([
                            'association_id' => $association->id, 'martial_art_id' => $martialArt->id, 'parent_id' => $parent?->id,
                            'kind' => $node['kind'], 'knowledge_type' => $nodeKnowledge, 'name' => $node['name'],
                            'original_name' => $node['original_name'] ?? null, 'description' => $node['description'] ?? null,
                            'video_url' => $node['video_url'] ?? null, 'sort_order' => ($index + 1) * 10, 'status' => 'active',
                        ]);
                        $byPath->put($path, $item);
                        $created++;
                    }
                    $create($node['children'] ?? [], $item, $path, $nodeKnowledge);
                }
            };
            $create($template['items'], null, '', null);

            $ranks = Rank::query()->where('martial_art_id', $martialArt->id)->get()->keyBy('name');
            $entries = 0;
            $missing = [];
            foreach ($template['curriculum'] as $rankName => $rows) {
                $rank = $ranks->get($rankName);
                if ($rank === null) {
                    $missing[] = $rankName;

                    continue;
                }
                foreach ($rows as [$path, $level]) {
                    $entry = RankCurriculumEntry::query()->firstOrCreate(
                        ['association_id' => $association->id, 'rank_id' => $rank->id, 'technique_item_id' => $byPath->get($path)->id],
                        ['required_level' => $level, 'updated_by_user_id' => $actor->id],
                    );
                    $entries += $entry->wasRecentlyCreated ? 1 : 0;
                }
            }
            $this->audit->record('technique_template.applied', 'created', $martialArt, null, [
                'template_key' => $key, 'items_created' => $created, 'entries_created' => $entries, 'missing_ranks' => $missing,
            ], ['association_id' => $association->id]);

            return ['items_created' => $created, 'entries_created' => $entries, 'missing_ranks' => $missing];
        });
    }

    /** @return list<int> */
    private function descendantIds(Collection $items, int $id): array
    {
        $ids = [];
        foreach ($items->where('parent_id', $id) as $child) {
            $ids[] = $child->id;
            array_push($ids, ...$this->descendantIds($items, $child->id));
        }

        return $ids;
    }
}

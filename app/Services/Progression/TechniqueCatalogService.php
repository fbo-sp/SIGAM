<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\AuditLog;
use App\Models\MartialArt;
use App\Models\QuizQuestion;
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
     * Árvore aninhada para exibição; `count` é o número de técnicas (não grupos) abaixo do nó.
     *
     * @param  Collection<int, TechniqueItem>  $items
     * @return list<array{item: TechniqueItem, children: list<array>, count: int}>
     */
    public function tree(Collection $items): array
    {
        $children = $items->groupBy(fn (TechniqueItem $item) => (int) $item->parent_id);
        $build = function (int $parentId) use (&$build, $children): array {
            return $children->get($parentId, collect())->map(function (TechniqueItem $item) use ($build): array {
                $nodes = $build($item->id);

                return ['item' => $item, 'children' => $nodes, 'count' => array_sum(array_map(fn (array $node) => $node['item']->kind === 'item' ? 1 : $node['count'], $nodes))];
            })->values()->all();
        };

        return $build(0);
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
                'translation' => $data['translation'] ?? null, 'key_points' => $data['key_points'] ?? null,
                'common_error' => $data['common_error'] ?? null, 'steps' => $data['steps'] ?? null,
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
     * Com $updateCurriculum, as exigências que vieram do modelo e nunca foram ajustadas na tela (sem registro
     * rank_curriculum.saved na auditoria) passam a seguir a versão atual do modelo: mudam de nível ou saem.
     * Cada troca fica na auditoria com o valor anterior.
     *
     * @return array{items_created: int, entries_created: int, entries_updated: int, entries_removed: int, entries_kept: int, questions_created: int, missing_ranks: list<string>}
     */
    public function applyTemplate(Association $association, MartialArt $martialArt, string $key, User $actor, bool $updateCurriculum = false): array
    {
        $template = $this->templates->find($key);
        if ($template === null) {
            throw ValidationException::withMessages(['template_key' => 'Selecione um modelo válido.']);
        }

        return DB::transaction(function () use ($association, $martialArt, $key, $template, $actor, $updateCurriculum): array {
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
            $expected = [];
            foreach ($template['curriculum'] as $rankName => $rows) {
                $rank = $ranks->get($rankName);
                if ($rank === null) {
                    $missing[] = $rankName;

                    continue;
                }
                foreach ($rows as [$path, $level]) {
                    $itemId = $byPath->get($path)->id;
                    $expected[$rank->id.':'.$itemId] = $level;
                    $entry = RankCurriculumEntry::query()->firstOrCreate(
                        ['association_id' => $association->id, 'rank_id' => $rank->id, 'technique_item_id' => $itemId],
                        ['required_level' => $level, 'updated_by_user_id' => $actor->id],
                    );
                    $entries += $entry->wasRecentlyCreated ? 1 : 0;
                }
            }
            $sync = $updateCurriculum ? $this->syncTemplateCurriculum($association, $ranks->pluck('id')->all(), $expected, $actor) : ['updated' => 0, 'removed' => 0, 'kept' => 0];
            $questions = 0;
            foreach ($template['quiz'] ?? [] as $row) {
                $rank = $ranks->get($row['rank']);
                if ($rank === null || QuizQuestion::query()->where('association_id', $association->id)->where('rank_id', $rank->id)->where('statement', $row['statement'])->exists()) {
                    continue;
                }
                $question = QuizQuestion::query()->create([
                    'association_id' => $association->id, 'martial_art_id' => $martialArt->id, 'rank_id' => $rank->id,
                    'technique_item_id' => isset($row['item']) ? $byPath->get($row['item'])?->id : null,
                    'statement' => $row['statement'], 'explanation' => $row['explanation'] ?? null, 'status' => 'active', 'created_by_user_id' => $actor->id,
                ]);
                foreach (array_values($row['options']) as $index => $text) {
                    $question->options()->create(['text' => $text, 'is_correct' => $index === (int) $row['correct'], 'sort_order' => $index + 1]);
                }
                $questions++;
            }
            $this->audit->record('technique_template.applied', 'created', $martialArt, null, [
                'template_key' => $key, 'items_created' => $created, 'entries_created' => $entries, 'questions_created' => $questions, 'missing_ranks' => $missing,
                'curriculum_updated' => $updateCurriculum, 'entries_updated' => $sync['updated'], 'entries_removed' => $sync['removed'], 'entries_kept' => $sync['kept'],
            ], ['association_id' => $association->id]);

            return [
                'items_created' => $created, 'entries_created' => $entries, 'entries_updated' => $sync['updated'], 'entries_removed' => $sync['removed'],
                'entries_kept' => $sync['kept'], 'questions_created' => $questions, 'missing_ranks' => $missing,
            ];
        });
    }

    /**
     * Alinha ao modelo as exigências nunca ajustadas na tela. Quem a associação gravou pelo currículo fica como está.
     *
     * @param  list<int>  $rankIds
     * @param  array<string, ?int>  $expected  nível do modelo por "rank_id:technique_item_id"
     * @return array{updated: int, removed: int, kept: int}
     */
    private function syncTemplateCurriculum(Association $association, array $rankIds, array $expected, User $actor): array
    {
        $current = RankCurriculumEntry::query()->where('association_id', $association->id)->whereIn('rank_id', $rankIds)->lockForUpdate()->get();
        $adjusted = AuditLog::query()->where('association_id', $association->id)->where('event_type', 'rank_curriculum.saved')
            ->where('entity_type', (new RankCurriculumEntry)->getMorphClass())->whereIn('entity_id', $current->pluck('id'))
            ->distinct()->pluck('entity_id')->flip();
        $result = ['updated' => 0, 'removed' => 0, 'kept' => $adjusted->count()];
        foreach ($current as $entry) {
            if ($adjusted->has($entry->id)) {
                continue;
            }
            $key = $entry->rank_id.':'.$entry->technique_item_id;
            if (! array_key_exists($key, $expected)) {
                $this->audit->record('rank_curriculum.template_removed', 'deleted', $entry, ['required_level' => $entry->required_level], null, ['association_id' => $association->id]);
                $entry->delete();
                $result['removed']++;
            } elseif (($before = $entry->required_level === null ? null : (int) $entry->required_level) !== $expected[$key]) {
                $entry->forceFill(['required_level' => $expected[$key], 'updated_by_user_id' => $actor->id])->save();
                $this->audit->record('rank_curriculum.template_updated', 'updated', $entry, ['required_level' => $before], ['required_level' => $expected[$key]], ['association_id' => $association->id]);
                $result['updated']++;
            }
        }

        return $result;
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

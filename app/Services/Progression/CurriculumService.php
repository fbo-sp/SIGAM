<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\Rank;
use App\Models\RankCurriculumEntry;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Currículo cumulativo por graduação (requisito D14): cada faixa herda as exigências das faixas anteriores
 * da mesma modalidade e guarda só os próprios ajustes (incluir, subir nível ou deixar de cobrar).
 */
class CurriculumService
{
    public function __construct(private readonly AuditService $audit, private readonly TechniqueCatalogService $catalog) {}

    /**
     * Currículo efetivo da faixa, já com a herança aplicada.
     *
     * Em cada linha, origin é new (incluído nesta faixa), raised (nível alterado nesta faixa) ou inherited.
     *
     * @return array{rows: list<array{item: TechniqueItem, path: string, level: int, origin: string, rank: Rank, previous_level: ?int}>, removed: list<array{item: TechniqueItem, path: string, previous_level: int}>}
     */
    public function effective(Association $association, Rank $rank): array
    {
        $ranks = Rank::query()->where('martial_art_id', $rank->martial_art_id)->where('sort_order', '<=', $rank->sort_order)->orderBy('sort_order')->get();
        $entries = RankCurriculumEntry::query()->where('association_id', $association->id)->whereIn('rank_id', $ranks->pluck('id'))->get()->groupBy('rank_id');
        $items = $this->catalog->items($association, $rank->martialArt);
        $byId = $items->keyBy('id');
        $inactive = $this->catalog->inactiveIds($items);
        $paths = $this->catalog->paths($items);

        $current = [];
        $previous = [];
        foreach ($ranks as $step) {
            $previous = $current;
            foreach ($entries->get($step->id, collect()) as $entry) {
                if ($entry->required_level === null) {
                    unset($current[$entry->technique_item_id]);
                } else {
                    $current[$entry->technique_item_id] = ['level' => (int) $entry->required_level, 'rank' => $step];
                }
            }
        }

        $rows = [];
        foreach ($current as $itemId => $requirement) {
            $item = $byId->get($itemId);
            if ($item === null || isset($inactive[$itemId])) {
                continue;
            }
            $before = $previous[$itemId]['level'] ?? null;
            $origin = $requirement['rank']->id !== $rank->id ? 'inherited' : ($before === null ? 'new' : 'raised');
            $rows[] = ['item' => $item, 'path' => $paths[$itemId], 'level' => $requirement['level'], 'origin' => $origin, 'rank' => $requirement['rank'], 'previous_level' => $before];
        }
        $removed = [];
        foreach (array_diff_key($previous, $current) as $itemId => $requirement) {
            if (($item = $byId->get($itemId)) && ! isset($inactive[$itemId])) {
                $removed[] = ['item' => $item, 'path' => $paths[$itemId], 'previous_level' => $requirement['level']];
            }
        }
        usort($rows, fn (array $a, array $b) => [$a['item']->knowledge_type === 'practical', $a['path']] <=> [$b['item']->knowledge_type === 'practical', $b['path']]);
        usort($removed, fn (array $a, array $b) => $a['path'] <=> $b['path']);

        return ['rows' => $rows, 'removed' => $removed];
    }

    /** Nível exigido a partir desta faixa; null deixa de cobrar um item herdado. */
    public function set(Association $association, Rank $rank, TechniqueItem $item, ?int $level, User $actor): RankCurriculumEntry
    {
        $this->ensureSameCatalog($association, $rank, $item);
        if ($level !== null && ! array_key_exists($level, AssessmentLevel::requirable())) {
            throw ValidationException::withMessages(['required_level' => 'Escolha um nível exigido válido.']);
        }
        if ($item->status !== 'active') {
            throw ValidationException::withMessages(['technique_item_id' => 'Este item está inativo no catálogo.']);
        }

        return DB::transaction(function () use ($association, $rank, $item, $level, $actor): RankCurriculumEntry {
            $entry = RankCurriculumEntry::query()->lockForUpdate()->firstOrNew(['association_id' => $association->id, 'rank_id' => $rank->id, 'technique_item_id' => $item->id]);
            $before = $entry->exists ? ['required_level' => $entry->required_level] : null;
            $entry->fill(['required_level' => $level, 'updated_by_user_id' => $actor->id])->save();
            $this->audit->record('rank_curriculum.saved', $before === null ? 'created' : 'updated', $entry, $before, ['required_level' => $level, 'rank_id' => $rank->id, 'technique_item_id' => $item->id], ['association_id' => $association->id]);

            return $entry;
        });
    }

    /** Desfaz o ajuste da faixa; o item volta a seguir o que vem das faixas anteriores. */
    public function restore(Association $association, Rank $rank, TechniqueItem $item): void
    {
        $this->ensureSameCatalog($association, $rank, $item);
        DB::transaction(function () use ($association, $rank, $item): void {
            $entry = RankCurriculumEntry::query()->where('association_id', $association->id)->where('rank_id', $rank->id)->where('technique_item_id', $item->id)->lockForUpdate()->first();
            if ($entry === null) {
                return;
            }
            $this->audit->record('rank_curriculum.restored', 'deleted', $entry, ['required_level' => $entry->required_level], null, ['association_id' => $association->id]);
            $entry->delete();
        });
    }

    /** Ajustes gravados na própria faixa, por item. @return Collection<int, RankCurriculumEntry> */
    public function ownEntries(Association $association, Rank $rank): Collection
    {
        return RankCurriculumEntry::query()->where('association_id', $association->id)->where('rank_id', $rank->id)->get()->keyBy('technique_item_id');
    }

    private function ensureSameCatalog(Association $association, Rank $rank, TechniqueItem $item): void
    {
        abort_unless($item->association_id === $association->id && $item->martial_art_id === $rank->martial_art_id, 404);
    }
}

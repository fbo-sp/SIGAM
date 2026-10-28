<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\RankCurriculumEntry;
use App\Models\Student;
use App\Models\TechniqueItem;
use App\Models\TechniqueStudyLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Área de estudo do portal (decisões de Fabio em 28/10/2026): todo o catálogo ativo da modalidade, com destaque para o
 * que o professor pediu para melhorar e para a próxima faixa; "Estudei esta técnica" registra um estudo por dia, que
 * o professor vê na ficha de acompanhamento. A ficha (imagem ou PDF) fica no disco privado.
 */
class TechniqueStudyService
{
    public const FILTERS = ['melhorar' => 'Para melhorar', 'proxima' => 'Próxima faixa', 'aprendidas' => 'Já aprendidas', 'todas' => 'Todo o catálogo'];

    public const SHEET_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private readonly TechniqueCatalogService $catalog,
        private readonly StudentProgressService $progress,
        private readonly AuditService $audit,
    ) {}

    public function storeSheet(Association $association, TechniqueItem $item, UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $extension = self::SHEET_MIMES[$mime] ?? null;
        abort_if($extension === null, 422);
        $path = 'technique-sheets/'.$association->id.'/'.$item->id.'-'.Str::random(20).'.'.$extension;
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $old = $item->sheet_path;
        $item->update(['sheet_path' => $path, 'sheet_mime' => $mime, 'sheet_name' => Str::limit($file->getClientOriginalName(), 150, '')]);
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
        $this->audit->record('technique_item.sheet_saved', 'updated', $item, ['sheet' => $old !== null], ['sheet' => true, 'mime' => $mime], ['association_id' => $association->id]);
    }

    public function removeSheet(Association $association, TechniqueItem $item): void
    {
        if ($item->sheet_path === null) {
            return;
        }
        Storage::disk('local')->delete($item->sheet_path);
        $item->update(['sheet_path' => null, 'sheet_mime' => null, 'sheet_name' => null]);
        $this->audit->record('technique_item.sheet_removed', 'updated', $item, ['sheet' => true], ['sheet' => false], ['association_id' => $association->id]);
    }

    /**
     * Itens ativos da modalidade com a situação do aluno (quando estão no currículo da próxima faixa) e a faixa em que
     * aparecem pela primeira vez no currículo.
     *
     * @return array{snapshot: array, rows: Collection<int, array{item: TechniqueItem, path: string, group: string, row: ?array, first_rank: ?Rank, studied: ?array}>}
     */
    public function catalog(Association $association, Student $student, MartialArt $martialArt): array
    {
        $snapshot = $this->progress->snapshot($association, $student, $martialArt);
        $items = $this->catalog->items($association, $martialArt);
        $inactive = $this->catalog->inactiveIds($items);
        $paths = $this->catalog->paths($items);
        $curriculum = $snapshot['rows']->flatten(1)->keyBy(fn (array $row) => $row['item']->id);
        $firstRanks = $this->firstRanks($association, $martialArt);
        $studied = $this->studyCounts($student, $martialArt);
        $rows = $items->filter(fn (TechniqueItem $item) => $item->kind === 'item' && ! isset($inactive[$item->id]))
            ->map(fn (TechniqueItem $item) => [
                'item' => $item,
                'path' => $paths[$item->id],
                'group' => Str::contains($paths[$item->id], ' > ') ? Str::beforeLast($paths[$item->id], ' > ') : $item->knowledgeLabel(),
                'row' => $curriculum->get($item->id),
                'first_rank' => $firstRanks[$item->id] ?? null,
                'studied' => $studied[$item->id] ?? null,
            ])
            ->sortBy(fn (array $row) => [$row['item']->knowledge_type === 'practical', $row['path']])->values();

        return ['snapshot' => $snapshot, 'rows' => $rows];
    }

    /** Filtro da lista; "Para melhorar" também traz o item com dica do professor. */
    public function filter(Collection $rows, string $filter, ?string $term): Collection
    {
        $term = mb_strtolower(trim((string) $term));

        return $rows->filter(fn (array $row) => match ($filter) {
            'melhorar' => $row['row'] && $row['row']['missing'] && ($row['row']['status'] === 'improve' || $row['row']['tip']),
            'proxima' => $row['row'] && $row['row']['missing'],
            'aprendidas' => $row['row'] && ! $row['row']['missing'],
            default => true,
        })->filter(fn (array $row) => $term === '' || str_contains(mb_strtolower($row['item']->name.' '.$row['item']->original_name.' '.$row['item']->translation.' '.$row['path']), $term))->values();
    }

    /** Registra o estudo de hoje; repetir no mesmo dia não cria outro registro. */
    public function markStudied(User $user, Student $student, TechniqueItem $item): bool
    {
        return DB::transaction(function () use ($user, $student, $item): bool {
            $exists = TechniqueStudyLog::query()->where('student_id', $student->id)->where('technique_item_id', $item->id)->whereDate('studied_on', today())->lockForUpdate()->exists();
            if ($exists) {
                return false;
            }
            try {
                $log = TechniqueStudyLog::query()->create(['association_id' => $student->association_id, 'student_id' => $student->id, 'technique_item_id' => $item->id, 'user_id' => $user->id, 'studied_on' => today()]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }
            $this->audit->record('technique_study.logged', 'created', $log, null, ['technique_item_id' => $item->id], ['association_id' => $student->association_id]);

            return true;
        });
    }

    /** @return array<int, array{count: int, last: Carbon, name: string}> item_id => estudos registrados pelo aluno */
    public function studyCounts(Student $student, MartialArt $martialArt, ?Carbon $from = null): array
    {
        $names = TechniqueItem::query()->where('association_id', $student->association_id)->where('martial_art_id', $martialArt->id)->pluck('name', 'id');

        return TechniqueStudyLog::query()->where('student_id', $student->id)
            ->whereHas('item', fn ($query) => $query->where('martial_art_id', $martialArt->id))
            ->when($from, fn ($query) => $query->whereDate('studied_on', '>=', $from))
            ->selectRaw('technique_item_id, count(*) as total, max(studied_on) as last_on')->groupBy('technique_item_id')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->technique_item_id => ['count' => (int) $row->total, 'last' => Carbon::parse($row->last_on), 'name' => (string) ($names[$row->technique_item_id] ?? '')]])->all();
    }

    /** @return array<int, Rank> item_id => primeira graduação cujo currículo pede o item */
    public function firstRanks(Association $association, MartialArt $martialArt): array
    {
        $ranks = Rank::query()->where('martial_art_id', $martialArt->id)->orderBy('sort_order')->get()->keyBy('id');
        $first = [];
        RankCurriculumEntry::query()->where('association_id', $association->id)->whereIn('rank_id', $ranks->keys())->whereNotNull('required_level')
            ->get(['rank_id', 'technique_item_id'])->sortBy(fn ($entry) => $ranks[$entry->rank_id]->sort_order)
            ->each(function ($entry) use (&$first, $ranks): void {
                $first[$entry->technique_item_id] ??= $ranks[$entry->rank_id];
            });

        return $first;
    }
}

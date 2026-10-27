<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\Student;
use App\Models\StudentMartialArt;
use App\Models\StudentRankHistory;
use App\Models\TechniqueAssessment;
use App\Models\TechniqueItem;
use App\Support\Progression\AssessmentLevel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Situação do aluno frente ao currículo da próxima graduação (requisito D12): lista do que falta,
 * separada em teórico e prático, idade e tempo mínimo, e radar por grupo. Sem percentual.
 */
class StudentProgressService
{
    /** @var array<string, mixed> memória por requisição para painéis com muitos alunos */
    private array $memo = [];

    public function __construct(
        private readonly CurriculumService $curriculum,
        private readonly AssessmentService $assessments,
        private readonly TechniqueCatalogService $catalog,
        private readonly SessionTechniqueService $sessionTechniques,
    ) {}

    /** Modalidades vigentes do aluno, para escolher o acompanhamento. @return Collection<int, StudentMartialArt> */
    public function martialArts(Student $student): Collection
    {
        return $student->martialArts()->where('status', 'active')->with(['martialArt', 'currentRank'])->get()
            ->unique('martial_art_id')->sortBy('martialArt.name')->values();
    }

    /**
     * @param  Collection<int, TechniqueAssessment>|null  $levels  níveis já carregados (painéis)
     */
    public function snapshot(Association $association, Student $student, MartialArt $martialArt, ?Collection $levels = null, bool $details = true): array
    {
        $link = $student->relationLoaded('martialArts')
            ? $student->martialArts->where('martial_art_id', $martialArt->id)->where('status', 'active')->sortByDesc('start_date')->first()
            : $student->martialArts()->where('martial_art_id', $martialArt->id)->where('status', 'active')->latest('start_date')->first();
        $ranks = $this->memo['ranks'][$martialArt->id] ??= Rank::query()->where('martial_art_id', $martialArt->id)->orderBy('sort_order')->get();
        $currentRank = $link?->current_rank_id ? $ranks->firstWhere('id', $link->current_rank_id) : null;
        $nextRank = $ranks->where('status', 'active')->when($currentRank, fn ($all) => $all->where('sort_order', '>', $currentRank->sort_order))->first();
        $levels ??= $this->assessments->current($student, $martialArt);
        $axes = $this->axes($association, $martialArt);
        $rows = [];
        $since = $details ? $this->rankSince($link) : null;
        $trained = $details && $nextRank ? $this->sessionTechniques->trainedDates($student, $martialArt, $since) : [];
        if ($nextRank) {
            $effective = $this->memo['curriculum'][$nextRank->id] ??= $this->curriculum->effective($association, $nextRank)['rows'];
            foreach ($effective as $row) {
                $assessment = $levels->get($row['item']->id);
                $missing = ($assessment?->level ?? AssessmentLevel::NOT_STARTED) < $row['level'];
                $trainedOn = isset($trained[$row['item']->id]) ? Carbon::parse(end($trained[$row['item']->id])) : null;
                $rows[] = $row + [
                    'axis' => $axes['axis'][$row['item']->id] ?? $row['item']->name,
                    'current' => $assessment?->level,
                    'assessed_on' => $assessment?->assessed_on,
                    'missing' => $missing,
                    'trained_on' => $trainedOn,
                    'tip' => $assessment?->student_tip,
                    'status' => $details ? $this->status($missing, $assessment, $trainedOn) : null,
                ];
            }
        }
        $byType = collect($rows)->groupBy(fn (array $row) => $row['item']->knowledge_type);
        $statuses = collect($rows)->where('missing', true)->countBy('status');

        return [
            'link' => $link,
            'current_rank' => $currentRank,
            'next_rank' => $nextRank,
            'since' => $since,
            'rows' => $byType,
            'missing' => $byType->map(fn (Collection $group) => $group->where('missing', true)->values()),
            'statuses' => ['untrained' => $statuses->get('untrained', 0), 'improve' => $statuses->get('improve', 0), 'awaiting' => $statuses->get('awaiting', 0)],
            'requirements' => $nextRank && $details ? $this->requirements($student, $link, $nextRank) : [],
            'radar' => $byType->map(fn (Collection $group) => $this->radar($group)),
        ];
    }

    /** Situações de um item abaixo do pedido, para "O que falta". */
    public const STATUS_LABELS = ['improve' => 'Precisa melhorar', 'untrained' => 'Ainda não treinado', 'awaiting' => 'Aguardando avaliação'];

    /**
     * Situação do item: no nível, precisa melhorar (avaliado abaixo do pedido), aguardando avaliação (treinado em aula
     * sem avaliação depois) ou ainda não treinado. "Não iniciado" conta como sem avaliação.
     */
    private function status(bool $missing, ?TechniqueAssessment $assessment, ?CarbonInterface $trainedOn): string
    {
        if (! $missing) {
            return 'ok';
        }
        $assessed = $assessment && $assessment->level > AssessmentLevel::NOT_STARTED;
        if ($assessed) {
            return 'improve';
        }

        return $trainedOn && (! $assessment || $trainedOn->gt($assessment->assessed_on)) ? 'awaiting' : 'untrained';
    }

    /** Desde quando o aluno está na graduação atual (última promoção registrada ou início na modalidade). */
    public function rankSince(?StudentMartialArt $link): ?Carbon
    {
        if (! $link) {
            return null;
        }
        $since = $link->current_rank_id ? StudentRankHistory::query()->where('student_martial_art_id', $link->id)->where('rank_id', $link->current_rank_id)->max('achieved_at') : null;
        $since ??= $link->start_date;

        return $since ? Carbon::parse($since)->startOfDay() : null;
    }

    /**
     * Linha do tempo da próxima graduação: porcentagem dos itens cobrados que estavam no nível pedido a cada
     * data de avaliação (desde a graduação atual) e, por item, cada avaliação e os dias de treino em aula.
     *
     * @param  array<string, mixed>  $snapshot  resultado de snapshot()
     * @return array{from: ?Carbon, overall: list<array{date: Carbon, value: float}>, items: Collection<int, array<string, mixed>>}
     */
    public function timeline(Student $student, MartialArt $martialArt, array $snapshot): array
    {
        $rows = $snapshot['rows']->flatten(1);
        if ($rows->isEmpty()) {
            return ['from' => $snapshot['since'], 'overall' => [], 'items' => collect()];
        }
        $required = $rows->mapWithKeys(fn (array $row) => [$row['item']->id => $row['level']]);
        $records = $this->assessments->timeline($student, $martialArt, $required->keys()->all());
        $trained = $this->sessionTechniques->trainedDates($student, $martialArt, $snapshot['since']);
        $from = $snapshot['since'] ?? $records->first()?->assessed_on?->copy();
        $levels = [];
        $points = [];
        $atLevel = fn (array $levels) => round(collect($levels)->filter(fn (int $level, int $id) => $level >= $required[$id])->count() * 100 / $required->count(), 1);
        foreach ($records->groupBy(fn (TechniqueAssessment $record) => $record->assessed_on->toDateString()) as $date => $day) {
            foreach ($day as $record) {
                $levels[$record->technique_item_id] = $record->level;
            }
            if ($from && $date < $from->toDateString()) {
                continue;
            }
            $points[] = ['date' => Carbon::parse($date), 'value' => $atLevel($levels)];
        }
        if ($from) {
            $before = $records->filter(fn (TechniqueAssessment $record) => $record->assessed_on->lt($from));
            $initial = $before->groupBy('technique_item_id')->map(fn (Collection $set) => $set->last()->level)
                ->filter(fn (int $level, int $id) => $level >= $required[$id])->count();
            array_unshift($points, ['date' => $from->copy(), 'value' => round($initial * 100 / $required->count(), 1), 'edge' => true]);
        }
        if ($points !== []) {
            $points[] = ['date' => today(), 'value' => end($points)['value'], 'edge' => true];
        }
        $byItem = $records->groupBy('technique_item_id');
        $items = $rows->filter(fn (array $row) => $byItem->has($row['item']->id) || isset($trained[$row['item']->id]))
            ->map(fn (array $row) => $row + [
                'records' => $byItem->get($row['item']->id, collect())->values(),
                'points' => $this->itemPoints($byItem->get($row['item']->id, collect()), $from),
                'trained' => array_map(fn (string $date) => Carbon::parse($date), $trained[$row['item']->id] ?? []),
            ])
            ->sortByDesc(fn (array $row) => $row['records']->last()?->assessed_on?->timestamp ?? 0)->values();

        return ['from' => $from, 'overall' => $points, 'items' => $items];
    }

    /**
     * Pontos do gráfico de um item: o nível no início do período (sem marcador) e cada avaliação a partir dele.
     *
     * @return list<array{date: Carbon, value: int, edge?: bool}>
     */
    private function itemPoints(Collection $records, ?Carbon $from): array
    {
        $before = $from ? $records->filter(fn (TechniqueAssessment $record) => $record->assessed_on->lt($from)) : collect();
        $points = $from ? [['date' => $from->copy(), 'value' => $before->last()?->level ?? AssessmentLevel::NOT_STARTED, 'edge' => true]] : [];
        foreach ($records->diffKeys($before) as $record) {
            $points[] = ['date' => $record->assessed_on, 'value' => $record->level, 'title' => AssessmentLevel::label($record->level)];
        }

        return $points;
    }

    /**
     * Eixo de cada item do catálogo: o grupo principal; quando o tipo de conhecimento tem um só grupo
     * principal (ex.: o bloco teórico), o grupo logo abaixo dele.
     *
     * @return array{axis: array<int, string>, type: array<int, string>, items: Collection<int, TechniqueItem>}
     */
    public function axes(Association $association, MartialArt $martialArt): array
    {
        return $this->memo['axes'][$association->id][$martialArt->id] ??= (function () use ($association, $martialArt): array {
            $items = $this->catalog->items($association, $martialArt);
            $paths = $this->catalog->paths($items);
            $singleRoot = $items->whereNull('parent_id')->groupBy('knowledge_type')->map(fn (Collection $roots) => $roots->count() === 1);
            $axis = [];
            foreach ($items as $item) {
                $parts = explode(' > ', $paths[$item->id]);
                $axis[$item->id] = ($singleRoot[$item->knowledge_type] ?? false) && count($parts) > 1 ? $parts[1] : $parts[0];
            }

            return ['axis' => $axis, 'type' => $items->pluck('knowledge_type', 'id')->all(), 'items' => $items->keyBy('id')];
        })();
    }

    /**
     * Em quantas aulas, entre as que o aluno esteve presente, cada eixo foi trabalhado (premissa 4).
     *
     * @return array{sessions: int, axes: Collection<string, list<array{label: string, sessions: int}>>}
     */
    public function worked(Association $association, Student $student, MartialArt $martialArt, CarbonInterface $from, CarbonInterface $until): array
    {
        $worked = $this->sessionTechniques->workedByStudent($student, $martialArt, $from, $until);
        $axes = $this->axes($association, $martialArt);
        $byAxis = [];
        foreach ($worked['techniques'] as $itemId => $sessionIds) {
            if (! isset($axes['axis'][$itemId])) {
                continue;
            }
            $key = $axes['type'][$itemId].'|'.$axes['axis'][$itemId];
            $byAxis[$key] = array_unique([...($byAxis[$key] ?? []), ...$sessionIds]);
        }
        $result = collect($byAxis)->map(fn (array $ids, string $key) => ['type' => strtok($key, '|'), 'label' => substr($key, strpos($key, '|') + 1), 'sessions' => count($ids)])
            ->sortByDesc('sessions')->groupBy('type')->map(fn (Collection $rows) => $rows->map(fn (array $row) => ['label' => $row['label'], 'sessions' => $row['sessions']])->values()->all());

        return ['sessions' => $worked['sessions'], 'axes' => $result];
    }

    /** Idade e tempo mínimo na faixa atual exigidos pela próxima graduação. */
    private function requirements(Student $student, ?StudentMartialArt $link, Rank $nextRank): array
    {
        $requirements = [];
        if ($nextRank->minimum_age !== null) {
            $birth = $student->person?->birth_date;
            $requirements[] = [
                'label' => "Idade mínima: {$nextRank->minimum_age} anos",
                'met' => $birth ? $birth->copy()->addYears($nextRank->minimum_age)->lte(today()) : null,
                'detail' => $birth ? 'Idade atual: '.$birth->age.' anos' : 'Data de nascimento não informada',
            ];
        }
        if ($nextRank->minimum_months !== null) {
            $since = $this->rankSince($link);
            $requirements[] = [
                'label' => "Tempo mínimo na faixa atual: {$nextRank->minimum_months} meses",
                'met' => $since ? $since->copy()->addMonths($nextRank->minimum_months)->lte(today()) : null,
                'detail' => $since ? 'Na faixa atual desde '.$since->format('d/m/Y').'; pode a partir de '.$since->copy()->addMonths($nextRank->minimum_months)->format('d/m/Y') : 'Data da faixa atual não informada',
            ];
        }

        return $requirements;
    }

    /**
     * Radar por eixo (ver axes()): média do nível atual e do exigido nos itens cobrados.
     *
     * @return list<array{label: string, current: float, required: float, items: int}>
     */
    private function radar(Collection $rows): array
    {
        $axes = [];
        foreach ($rows as $row) {
            $axes[$row['axis']][] = $row;
        }

        return collect($axes)->map(fn (array $items, string $label) => [
            'label' => $label,
            'current' => round(collect($items)->avg(fn (array $row) => $row['current'] ?? AssessmentLevel::NOT_STARTED), 2),
            'required' => round(collect($items)->avg('level'), 2),
            'items' => count($items),
        ])->values()->all();
    }
}

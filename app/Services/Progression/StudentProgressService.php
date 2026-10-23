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
        if ($nextRank) {
            $effective = $this->memo['curriculum'][$nextRank->id] ??= $this->curriculum->effective($association, $nextRank)['rows'];
            foreach ($effective as $row) {
                $assessment = $levels->get($row['item']->id);
                $rows[] = $row + [
                    'axis' => $axes['axis'][$row['item']->id] ?? $row['item']->name,
                    'current' => $assessment?->level,
                    'assessed_on' => $assessment?->assessed_on,
                    'missing' => ($assessment?->level ?? AssessmentLevel::NOT_STARTED) < $row['level'],
                ];
            }
        }
        $byType = collect($rows)->groupBy(fn (array $row) => $row['item']->knowledge_type);

        return [
            'link' => $link,
            'current_rank' => $currentRank,
            'next_rank' => $nextRank,
            'rows' => $byType,
            'missing' => $byType->map(fn (Collection $group) => $group->where('missing', true)->values()),
            'requirements' => $nextRank && $details ? $this->requirements($student, $link, $nextRank) : [],
            'radar' => $byType->map(fn (Collection $group) => $this->radar($group)),
        ];
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
            $since = $link ? (StudentRankHistory::query()->where('student_martial_art_id', $link->id)->where('rank_id', $link->current_rank_id)
                ->max('achieved_at') ?? $link->start_date) : null;
            $since = $since ? Carbon::parse($since) : null;
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

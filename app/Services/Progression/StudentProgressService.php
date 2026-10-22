<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\Student;
use App\Models\StudentMartialArt;
use App\Models\StudentRankHistory;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Situação do aluno frente ao currículo da próxima graduação (requisito D12): lista do que falta,
 * separada em teórico e prático, idade e tempo mínimo, e radar por grupo. Sem percentual.
 */
class StudentProgressService
{
    public function __construct(private readonly CurriculumService $curriculum, private readonly AssessmentService $assessments) {}

    /** Modalidades vigentes do aluno, para escolher o acompanhamento. @return Collection<int, StudentMartialArt> */
    public function martialArts(Student $student): Collection
    {
        return $student->martialArts()->where('status', 'active')->with(['martialArt', 'currentRank'])->get()
            ->unique('martial_art_id')->sortBy('martialArt.name')->values();
    }

    public function snapshot(Association $association, Student $student, MartialArt $martialArt): array
    {
        $link = $student->martialArts()->where('martial_art_id', $martialArt->id)->where('status', 'active')->with('currentRank')->latest('start_date')->first();
        $currentRank = $link?->currentRank;
        $nextRank = Rank::query()->where('martial_art_id', $martialArt->id)->where('status', 'active')
            ->when($currentRank, fn ($query) => $query->where('sort_order', '>', $currentRank->sort_order))
            ->orderBy('sort_order')->first();
        $levels = $this->assessments->current($student, $martialArt);
        $rows = [];
        if ($nextRank) {
            foreach ($this->curriculum->effective($association, $nextRank)['rows'] as $row) {
                $assessment = $levels->get($row['item']->id);
                $rows[] = $row + [
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
            'requirements' => $nextRank ? $this->requirements($student, $link, $nextRank) : [],
            'radar' => $byType->map(fn (Collection $group) => $this->radar($group)),
        ];
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
     * Eixos do radar: os grupos principais; quando só existe um grupo principal (ex.: o bloco teórico),
     * os grupos dentro dele. Valor é a média do nível atual e do exigido nos itens cobrados.
     *
     * @return list<array{label: string, current: float, required: float, items: int}>
     */
    private function radar(Collection $rows): array
    {
        $segments = $rows->map(fn (array $row) => explode(' > ', $row['path']));
        $singleRoot = $segments->map(fn (array $parts) => $parts[0])->unique()->count() === 1;
        $axes = [];
        foreach ($rows as $index => $row) {
            $parts = $segments[$index];
            $axis = $singleRoot && count($parts) > 1 ? $parts[1] : $parts[0];
            $axes[$axis][] = $row;
        }

        return collect($axes)->map(fn (array $items, string $label) => [
            'label' => $label,
            'current' => round(collect($items)->avg(fn (array $row) => $row['current'] ?? AssessmentLevel::NOT_STARTED), 2),
            'required' => round(collect($items)->avg('level'), 2),
            'items' => count($items),
        ])->values()->all();
    }
}

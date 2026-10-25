<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cobertura do currículo na turma: quanto do currículo acumulado da próxima faixa dos alunos foi marcado
 * em alguma aula da turma no período. Visão geral (todas as faixas buscadas juntas) e uma linha por faixa.
 */
class CurriculumCoverageService
{
    public const HIGH = 70;

    public const MEDIUM = 40;

    public function __construct(private readonly StudentProgressService $progress) {}

    /**
     * @return array{sessions: int, sessions_with_techniques: int, students: int, overall: array, ranks: Collection, without_next: Collection}
     */
    public function forClass(Association $association, ClassGroup $group, CarbonInterface $from, CarbonInterface $until): array
    {
        $group->loadMissing('martialArt');
        $sessions = ClassSession::query()->where('class_group_id', $group->id)->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->where('session_date', '>=', $from->toDateString())->where('session_date', '<', $until->copy()->addDay()->toDateString())
            ->where('session_date', '<', today()->addDay()->toDateString())->orderBy('session_date')->orderBy('start_time')->get(['id', 'session_date', 'start_time']);
        $marks = ClassSessionTechnique::query()->whereIn('class_session_id', $sessions->pluck('id'))->whereNull('removed_at')->get(['class_session_id', 'technique_item_id']);
        $worked = $marks->pluck('technique_item_id')->flip();
        $axes = $this->progress->axes($association, $group->martialArt);

        $ranks = [];
        $withoutNext = collect();
        $students = $this->students($group);
        foreach ($students as $student) {
            $snapshot = $this->progress->snapshot($association, $student, $group->martialArt, collect(), details: false);
            if (! $snapshot['next_rank']) {
                $withoutNext->push($student);

                continue;
            }
            $rankId = $snapshot['next_rank']->id;
            $ranks[$rankId] ??= ['rank' => $snapshot['next_rank'], 'students' => collect(), 'items' => $snapshot['rows']->flatten(1)->keyBy(fn (array $row) => $row['item']->id)];
            $ranks[$rankId]['students']->push(['student' => $student, 'current_rank' => $snapshot['current_rank']]);
        }

        $ranks = collect($ranks)->sortBy(fn (array $row) => $row['rank']->sort_order)->values()
            ->map(fn (array $row) => ['rank' => $row['rank'], 'students' => $row['students']] + $this->summary($row['items'], $worked, $axes));
        $all = collect();
        foreach ($ranks as $row) {
            foreach ($row['rows'] as $item) {
                $all->put($item['item']->id, $item);
            }
        }

        return [
            'sessions' => $sessions->count(),
            'sessions_with_techniques' => $marks->pluck('class_session_id')->unique()->count(),
            'students' => $students->count(),
            'overall' => $this->summary($all, $worked, $axes),
            'ranks' => $ranks,
            'without_next' => $withoutNext,
            'monthly' => $this->monthly($sessions, $marks, $axes, $from, $until),
            'map' => $this->map($sessions, $marks, $axes),
        ];
    }

    /**
     * Distribuição por grupo: em quantas aulas de cada mês cada grupo prático foi trabalhado; o bloco teórico
     * conta como um grupo só. Até sete grupos na ordem do catálogo, o resto em "Outros".
     *
     * @return array{months: list<array{key: string, label: string}>, series: list<array{label: string, values: array<string, int>}>}
     */
    private function monthly(Collection $sessions, Collection $marks, array $axes, CarbonInterface $from, CarbonInterface $until): array
    {
        $labels = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        $months = [];
        for ($month = $from->copy()->startOfMonth(); $month->lte($until); $month->addMonth()) {
            $months[] = ['key' => $month->format('Y-m'), 'label' => $labels[$month->month - 1].($month->year !== $until->year ? '/'.$month->format('y') : '')];
        }
        $group = fn (int $itemId) => ($axes['type'][$itemId] ?? null) === 'theoretical' ? 'Teórico' : ($axes['axis'][$itemId] ?? null);
        $order = collect($axes['items'])->keys()->map($group)->filter()->unique()
            ->sortBy(fn (string $label) => $label === 'Teórico' ? 1 : 0)->values();
        $kept = $order->take($order->count() > 8 ? 7 : 8)->all();
        $dates = $sessions->mapWithKeys(fn (ClassSession $session) => [$session->id => $session->session_date->format('Y-m')]);
        $pairs = [];
        foreach ($marks as $mark) {
            $label = $group($mark->technique_item_id);
            if ($label === null) {
                continue;
            }
            $label = in_array($label, $kept, true) ? $label : 'Outros';
            $pairs[$label][$dates[$mark->class_session_id]][$mark->class_session_id] = true;
        }
        $series = [];
        foreach ([...$kept, 'Outros'] as $label) {
            if (isset($pairs[$label])) {
                $series[] = ['label' => $label, 'values' => collect($months)->mapWithKeys(fn (array $month) => [$month['key'] => count($pairs[$label][$month['key']] ?? [])])->all()];
            }
        }

        return ['months' => $months, 'series' => $series];
    }

    /**
     * Mapa aula a aula: itens marcados nas últimas oito aulas do período (até 15, os mais frequentes).
     *
     * @return array{sessions: Collection<int, ClassSession>, rows: Collection, hidden: int}
     */
    private function map(Collection $sessions, Collection $marks, array $axes): array
    {
        $recent = $sessions->slice(-8)->values();
        $bySession = $marks->whereIn('class_session_id', $recent->pluck('id'))->groupBy('technique_item_id');
        $rows = $bySession->filter(fn (Collection $set, int $itemId) => isset($axes['items'][$itemId]))
            ->map(fn (Collection $set, int $itemId) => [
                'item' => $axes['items'][$itemId],
                'axis' => $axes['axis'][$itemId],
                'count' => $set->count(),
                'cells' => $recent->map(fn (ClassSession $session) => $set->contains('class_session_id', $session->id))->all(),
            ])->sortBy([['count', 'desc'], fn (array $a, array $b) => strcmp($a['item']->name, $b['item']->name)])->values();

        return ['sessions' => $recent, 'rows' => $rows->take(15), 'hidden' => max($rows->count() - 15, 0)];
    }

    /** Faixa de cor da barra: alta a partir de 70%, média de 40% a 69%, baixa abaixo. */
    public static function tone(int $percent): string
    {
        return $percent >= self::HIGH ? 'high' : ($percent >= self::MEDIUM ? 'medium' : 'low');
    }

    /**
     * @param  Collection<int, array>  $items  linhas do currículo efetivo, por item
     * @param  Collection<int, int>  $worked  itens marcados em aula (chaves)
     */
    private function summary(Collection $items, Collection $worked, array $axes): array
    {
        $rows = $items->map(fn (array $row) => $row + ['worked' => $worked->has($row['item']->id), 'axis' => $axes['axis'][$row['item']->id] ?? $row['item']->name])->values();
        $groups = $rows->groupBy(fn (array $row) => $row['item']->knowledge_type.'|'.$row['axis'])
            ->map(fn (Collection $set) => $this->counts($set) + ['label' => $set->first()['axis'], 'type' => $set->first()['item']->knowledge_type])
            ->sortBy(fn (array $group) => [$group['type'] === 'theoretical' ? 1 : 0, $group['label']])->values();

        return $this->counts($rows) + [
            'groups' => $groups,
            'rows' => $rows,
            'missing' => $rows->where('worked', false)->sortBy('path')->values(),
        ];
    }

    /** @return array{total: int, worked: int, percent: int, tone: string} */
    private function counts(Collection $rows): array
    {
        $total = $rows->count();
        $done = $rows->where('worked', true)->count();
        $percent = $total > 0 ? (int) floor($done * 100 / $total) : 0;

        return ['total' => $total, 'worked' => $done, 'percent' => $percent, 'tone' => self::tone($percent)];
    }

    /** @return Collection<int, Student> alunos ativos com matrícula vigente na turma */
    private function students(ClassGroup $group): Collection
    {
        $ids = ClassEnrollment::query()->where('class_group_id', $group->id)->where('status', 'active')
            ->whereDate('start_date', '<=', today())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->pluck('student_id');

        return Student::query()->whereKey($ids)->where('status', 'active')->with(['person:id,full_name,social_name,photo_path', 'martialArts'])->get()
            ->sortBy(fn (Student $student) => $student->person->social_name ?: $student->person->full_name)->values();
    }
}

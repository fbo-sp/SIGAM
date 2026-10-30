<?php

namespace App\Services\Exams;

use App\Models\ClassEnrollment;
use App\Models\Rank;
use App\Models\RankExam;
use App\Models\Student;
use App\Models\StudentMartialArt;
use App\Models\StudentRankHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alunos que podem entrar no exame e se estão aptos (proposta aprovada em 30/10/2026): ativos no nível do exame, com
 * próxima faixa ativa na modalidade. Apto = idade mínima e tempo mínimo na faixa atual da próxima faixa cumpridos na
 * data de início do exame. Dado que falta (sem nascimento ou sem data da faixa) aparece como "não verificado".
 */
class ExamCandidates
{
    /** @var array<int, Collection<int, Rank>> */
    private array $ranks = [];

    /**
     * @return Collection<int, array{student: Student, link: StudentMartialArt, current_rank: ?Rank, target_rank: Rank, since: ?Carbon, eligible: bool, unmet: list<string>, unknown: list<string>, months: ?int}>
     */
    public function forExam(RankExam $exam): Collection
    {
        return $this->evaluate($exam, $this->students($exam));
    }

    /** Alunos ativos do nível do exame com a modalidade ativa, sem a próxima faixa calculada. */
    public function students(RankExam $exam): Collection
    {
        $today = today()->toDateString();
        $query = Student::query()->where('association_id', $exam->association_id)->where('status', 'active')
            ->whereHas('martialArts', fn (Builder $link) => $link->where('martial_art_id', $exam->martial_art_id)->where('status', 'active'));
        match ($exam->scope_type) {
            'dojo' => $query->whereHas('dojoHistory', fn (Builder $history) => $history->where('dojo_id', $exam->dojo_id)->whereDate('start_date', '<=', $today)
                ->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))),
            'class' => $query->whereIn('id', ClassEnrollment::query()->select('student_id')->where('class_group_id', $exam->class_group_id)->where('status', 'active')
                ->whereDate('start_date', '<=', $today)->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))),
            'student' => $query->whereKey($exam->student_id),
            default => null,
        };

        return $query->with(['person:id,full_name,social_name,birth_date,photo_path', 'martialArts' => fn ($link) => $link->where('martial_art_id', $exam->martial_art_id)->where('status', 'active')])->get();
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    public function evaluate(RankExam $exam, Collection $students): Collection
    {
        $ranks = $this->ranks($exam->martial_art_id);
        $links = $students->mapWithKeys(fn (Student $student) => [$student->id => $student->martialArts->sortByDesc('start_date')->first()])->filter();
        $since = $this->since($links->values());
        $reference = Carbon::parse($exam->starts_on)->startOfDay();
        $rows = collect();
        foreach ($students as $student) {
            $link = $links->get($student->id);
            if (! $link) {
                continue;
            }
            $current = $link->current_rank_id ? $ranks->firstWhere('id', $link->current_rank_id) : null;
            $target = $ranks->where('status', 'active')->when($current, fn (Collection $all) => $all->where('sort_order', '>', $current->sort_order))->first();
            if (! $target) {
                continue;
            }
            $from = $since[$link->id] ?? null;
            $unmet = [];
            $unknown = [];
            if ($target->minimum_age !== null) {
                $birth = $student->person?->birth_date;
                if (! $birth) {
                    $unknown[] = 'sem data de nascimento';
                } elseif ($birth->copy()->addYears($target->minimum_age)->gt($reference)) {
                    $unmet[] = 'idade '.(int) floor($birth->diffInYears($reference))." (mínimo {$target->minimum_age})";
                }
            }
            $months = $from ? (int) floor($from->diffInMonths($reference)) : null;
            if ($target->minimum_months !== null) {
                if (! $from) {
                    $unknown[] = 'sem data da faixa atual';
                } elseif ($from->copy()->addMonths($target->minimum_months)->gt($reference)) {
                    $unmet[] = "{$months} meses na faixa (mínimo {$target->minimum_months})";
                }
            }
            $rows->push([
                'student' => $student, 'link' => $link, 'current_rank' => $current, 'target_rank' => $target, 'since' => $from,
                'eligible' => $unmet === [], 'unmet' => $unmet, 'unknown' => $unknown, 'months' => $months,
            ]);
        }

        return $rows->sortBy(fn (array $row) => [$row['target_rank']->sort_order, mb_strtolower($row['student']->person->social_name ?: $row['student']->person->full_name)])->values();
    }

    /**
     * Desde quando cada aluno está na faixa atual: última promoção registrada nessa faixa ou início na modalidade.
     *
     * @param  Collection<int, StudentMartialArt>  $links
     * @return array<int, Carbon>
     */
    public function since(Collection $links): array
    {
        $history = StudentRankHistory::query()->whereIn('student_martial_art_id', $links->pluck('id'))
            ->selectRaw('student_martial_art_id, rank_id, MAX(achieved_at) as achieved')->groupBy('student_martial_art_id', 'rank_id')->get()
            ->groupBy('student_martial_art_id');
        $result = [];
        foreach ($links as $link) {
            $date = $link->current_rank_id ? $history->get($link->id)?->firstWhere('rank_id', $link->current_rank_id)?->achieved : null;
            $date ??= $link->start_date;
            if ($date) {
                $result[$link->id] = Carbon::parse($date)->startOfDay();
            }
        }

        return $result;
    }

    /** @return Collection<int, Rank> */
    public function ranks(int $martialArtId): Collection
    {
        return $this->ranks[$martialArtId] ??= Rank::query()->where('martial_art_id', $martialArtId)->orderBy('sort_order')->get();
    }
}

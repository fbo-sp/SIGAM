<?php

namespace App\Services\Exams;

use App\Models\RankExam;
use App\Models\TechniqueItem;
use App\Services\Progression\SessionTechniqueService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicador "Seoi-nage · 10/15 aulas" (decisão de Fabio em 30/10/2026): aulas com chamada finalizada, no nível do
 * exame, em que algum aluno daquela faixa de destino esteve presente desde a última graduação dele; o número da
 * esquerda conta em quantas dessas aulas o item foi marcado (marcar um grupo inteiro cobre os itens dele).
 */
class ExamCoverageService
{
    /**
     * @param  array<int, ?Carbon>  $since  student_id => desde quando está na faixa atual
     * @return array{total: int, items: array<int, int>}
     */
    public function forRank(RankExam $exam, array $since): array
    {
        if ($since === []) {
            return ['total' => 0, 'items' => []];
        }
        $rows = DB::table('attendance')->join('class_sessions', 'class_sessions.id', '=', 'attendance.class_session_id')
            ->join('class_groups', 'class_groups.id', '=', 'class_sessions.class_group_id')
            ->whereIn('attendance.student_id', array_keys($since))->whereIn('attendance.status', SessionTechniqueService::PRESENT_STATUSES)
            ->where('class_sessions.attendance_state', 'closed')->whereNotIn('class_sessions.status', ['cancelled', 'rescheduled'])
            ->where('class_groups.association_id', $exam->association_id)->where('class_groups.martial_art_id', $exam->martial_art_id)
            ->when($exam->scope_type === 'dojo', fn ($query) => $query->where('class_groups.dojo_id', $exam->dojo_id))
            ->when($exam->scope_type === 'class', fn ($query) => $query->where('class_groups.id', $exam->class_group_id))
            ->get(['attendance.student_id', 'class_sessions.id', 'class_sessions.session_date']);
        $sessions = [];
        foreach ($rows as $row) {
            $from = $since[$row->student_id] ?? null;
            if ($from === null || substr((string) $row->session_date, 0, 10) >= $from->toDateString()) {
                $sessions[(int) $row->id] = true;
            }
        }
        if ($sessions === []) {
            return ['total' => 0, 'items' => []];
        }
        $children = [];
        foreach (TechniqueItem::query()->where('association_id', $exam->association_id)->where('martial_art_id', $exam->martial_art_id)->whereNotNull('parent_id')->pluck('parent_id', 'id') as $id => $parent) {
            $children[$parent][] = $id;
        }
        $marked = [];
        foreach (array_chunk(array_keys($sessions), 500) as $chunk) {
            foreach (DB::table('class_session_techniques')->whereIn('class_session_id', $chunk)->whereNull('removed_at')->get(['class_session_id', 'technique_item_id']) as $mark) {
                $stack = [(int) $mark->technique_item_id];
                while ($stack !== []) {
                    $id = array_pop($stack);
                    $marked[$id][(int) $mark->class_session_id] = true;
                    array_push($stack, ...($children[$id] ?? []));
                }
            }
        }

        return ['total' => count($sessions), 'items' => array_map('count', $marked)];
    }

    /** Cor do indicador: verde com metade das aulas ou mais, amarelo abaixo disso, vermelho quando nenhuma aula abordou. */
    public static function tone(int $covered, int $total): string
    {
        return $covered === 0 ? 'zero' : ($covered * 2 >= $total ? 'high' : 'mid');
    }

    /** @param  Collection<int, mixed>  $participants */
    public static function sinceByStudent(Collection $participants, array $sinceByLink): array
    {
        return $participants->mapWithKeys(fn ($participant) => [$participant->student_id => $sinceByLink[$participant->student_martial_art_id] ?? null])->all();
    }
}

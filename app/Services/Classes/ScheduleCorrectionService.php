<?php

namespace App\Services\Classes;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige um horário cadastrado errado desde o início (decisões de Fabio em 29/10/2026): o próprio horário muda, sem
 * nova versão, e todas as aulas dele vão para o horário certo, inclusive as com chamada finalizada e as reagendadas à
 * parte. Só gestores da turma corrigem. Aulas canceladas continuam canceladas; o horário delas também é ajustado para
 * que a vaga da data continue ocupada e o gerador de aulas não recrie a aula cancelada no horário novo.
 */
class ScheduleCorrectionService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{past: int, closed: int, future: int, cancelled: int, rescheduled: int, total: int} */
    public function impact(ClassSchedule $schedule): array
    {
        $sessions = $this->sessions($schedule);
        $live = $sessions->filter(fn (ClassSession $session) => $this->isLive($session));
        $past = $live->filter(fn (ClassSession $session) => $session->session_date->lt(today()));

        return [
            'past' => $past->count(),
            'closed' => $live->where('attendance_state', 'closed')->count(),
            'future' => $live->count() - $past->count(),
            'cancelled' => $sessions->where('status', 'cancelled')->count(),
            'rescheduled' => $live->whereNull('class_schedule_id')->count(),
            'total' => $live->count(),
        ];
    }

    /** @return array{updated: int} */
    public function correct(Association $association, ClassGroup $group, ClassSchedule $schedule, array $data): array
    {
        return DB::transaction(function () use ($association, $group, $schedule, $data): array {
            $lockedGroup = ClassGroup::query()->lockForUpdate()->findOrFail($group->id);
            $locked = ClassSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            abort_unless($lockedGroup->association_id === $association->id && $locked->class_group_id === $lockedGroup->id && $locked->status === 'active', 404);
            $start = $data['start_time'].':00';
            $end = $data['end_time'].':00';
            $old = [substr($locked->start_time, 0, 5), substr($locked->end_time, 0, 5)];
            if ($old === [$data['start_time'], $data['end_time']]) {
                throw ValidationException::withMessages(['start_time' => 'O horário informado é igual ao atual.']);
            }
            if ($lockedGroup->schedules()->where('status', 'active')->whereKeyNot($locked->id)->where('weekday', $locked->weekday)->whereTime('start_time', $start)->exists()) {
                throw ValidationException::withMessages(['start_time' => 'A turma já tem outro horário neste dia começando às '.$data['start_time'].'.']);
            }

            $sessions = $this->sessions($locked, true);
            $moving = $this->moving($sessions);
            $ids = $moving->pluck('id');
            $dates = $moving->map(fn (ClassSession $session) => $session->session_date->toDateString())->flip();
            $conflict = ClassSession::query()->where('class_group_id', $lockedGroup->id)->whereNotIn('id', $ids)->whereTime('start_time', $start)
                ->orderBy('session_date')->get()->first(fn (ClassSession $session) => $dates->has($session->session_date->toDateString()));
            if ($conflict) {
                throw ValidationException::withMessages(['start_time' => 'Já existe uma aula desta turma em '.$conflict->session_date->format('d/m/Y').' às '.$data['start_time'].'. Ajuste essa aula antes de corrigir.']);
            }

            $locked->update(['start_time' => $start, 'end_time' => $end]);
            ClassSession::query()->whereIn('id', $ids)->update(['start_time' => $start, 'end_time' => $end, 'updated_at' => now()]);
            $updated = $moving->filter(fn (ClassSession $session) => $this->isLive($session))->count();
            $this->audit->record('class_schedule.time_corrected', 'updated', $locked, ['start_time' => $old[0], 'end_time' => $old[1]], [
                'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
            ], ['association_id' => $association->id, 'class_group_id' => $lockedGroup->id, 'reason' => $data['reason'], 'sessions_updated' => $updated, 'session_ids' => $ids->all()]);

            return ['updated' => $updated];
        }, 3);
    }

    /** Aulas do horário e as que saíram delas por reagendamento (em cadeia), da mesma turma. */
    private function sessions(ClassSchedule $schedule, bool $lock = false): Collection
    {
        $query = fn () => ClassSession::query()->where('class_group_id', $schedule->class_group_id)->when($lock, fn ($q) => $q->lockForUpdate());
        $sessions = $query()->where('class_schedule_id', $schedule->id)->get();
        $parents = $sessions->where('status', 'rescheduled')->pluck('id');
        while ($parents->isNotEmpty()) {
            $children = $query()->whereIn('rescheduled_from_id', $parents)->whereNotIn('id', $sessions->pluck('id'))->get();
            $sessions = $sessions->concat($children);
            $parents = $children->where('status', 'rescheduled')->pluck('id');
        }

        return $sessions;
    }

    /**
     * Uma vaga por data: quando a aula foi reagendada para o mesmo dia, só a aula que vale muda de horário; a original
     * reagendada fica onde estava, porque a aula nova já ocupa a data no horário certo.
     */
    private function moving(Collection $sessions): Collection
    {
        return $sessions->groupBy(fn (ClassSession $session) => $session->session_date->toDateString())
            ->flatMap(function (Collection $sameDay) {
                if ($sameDay->count() === 1) {
                    return $sameDay;
                }
                $live = $sameDay->filter(fn (ClassSession $session) => $this->isLive($session));

                return $live->isNotEmpty() ? $live->take(1) : $sameDay->take(1);
            })->values();
    }

    private function isLive(ClassSession $session): bool
    {
        return ! in_array($session->status, ['cancelled', 'rescheduled'], true);
    }
}

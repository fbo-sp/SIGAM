<?php

namespace App\Services\Attendance;

use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Services\AuditService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FutureSessionMaintainer
{
    private const MAX_SEARCH_DAYS = 366;

    public function __construct(private readonly AuditService $audit) {}

    /** @return array{target: int, existing: int, created: int, missing: int} */
    public function maintain(ClassGroup|int $group, ?Carbon $reference = null): array
    {
        $groupId = $group instanceof ClassGroup ? $group->id : $group;
        $reference = ($reference ?: now())->copy()->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($groupId, $reference): array {
            $locked = ClassGroup::query()->with(['association', 'dojo'])->lockForUpdate()->findOrFail($groupId);
            $target = (int) $locked->future_session_target;
            if (! in_array($target, [4, 8], true)) {
                throw ValidationException::withMessages(['future_session_target' => 'Escolha manter 4 ou 8 próximas aulas.']);
            }

            if ($locked->status !== 'active' || $locked->association?->status !== 'active' || $locked->dojo?->status !== 'active') {
                return ['target' => $target, 'existing' => 0, 'created' => 0, 'missing' => $target];
            }

            $existing = $this->futurePlannedCount($locked, $reference);
            $needed = max(0, $target - $existing);
            if ($needed === 0) {
                return ['target' => $target, 'existing' => $existing, 'created' => 0, 'missing' => 0];
            }

            $created = $this->createMissing($locked, $reference, $needed);
            if ($created > 0) {
                $this->audit->record('class_sessions.replenished', 'created', $locked, null, [
                    'target' => $target,
                    'existing_before' => $existing,
                    'sessions_created' => $created,
                    'missing_after' => max(0, $needed - $created),
                ], ['association_id' => $locked->association_id]);
            }

            return [
                'target' => $target,
                'existing' => $existing,
                'created' => $created,
                'missing' => max(0, $needed - $created),
            ];
        }, 3);
    }

    public function maintainAll(?int $associationId = null, ?Carbon $reference = null): int
    {
        $created = 0;
        ClassGroup::query()->where('status', 'active')
            ->when($associationId !== null, fn ($query) => $query->where('association_id', $associationId))
            ->whereHas('association', fn ($query) => $query->where('status', 'active'))
            ->whereHas('dojo', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->eachById(function (ClassGroup $group) use (&$created, $reference): void {
                $created += $this->maintain($group->id, $reference)['created'];
            }, 100);

        return $created;
    }

    /** @return array{target: int, existing: int, created: int, missing: int} */
    public function updateTarget(ClassGroup $group, int $target, ?Carbon $reference = null): array
    {
        if (! in_array($target, [4, 8], true)) {
            throw ValidationException::withMessages(['future_session_target' => 'Escolha manter 4 ou 8 próximas aulas.']);
        }

        return DB::transaction(function () use ($group, $target, $reference): array {
            $locked = ClassGroup::query()->lockForUpdate()->findOrFail($group->id);
            $oldTarget = (int) $locked->future_session_target;
            $locked->update(['future_session_target' => $target]);
            $this->audit->record('class_group.future_session_target_updated', 'updated', $locked, [
                'future_session_target' => $oldTarget,
            ], ['future_session_target' => $target], ['association_id' => $locked->association_id]);

            return $this->maintain($locked->id, $reference);
        }, 3);
    }

    private function futurePlannedCount(ClassGroup $group, Carbon $reference): int
    {
        return $group->sessions()->where('status', 'planned')->where(function ($query) use ($reference): void {
            $query->whereDate('session_date', '>', $reference->toDateString())
                ->orWhere(function ($today) use ($reference): void {
                    $today->whereDate('session_date', $reference->toDateString())
                        ->whereTime('start_time', '>=', $reference->format('H:i:s'));
                });
        })->count();
    }

    private function createMissing(ClassGroup $group, Carbon $reference, int $needed): int
    {
        $searchStart = $reference->copy()->startOfDay()->max($group->start_date->copy()->startOfDay());
        $searchEnd = $searchStart->copy()->addDays(self::MAX_SEARCH_DAYS);
        if ($group->end_date !== null && $group->end_date->lt($searchEnd)) {
            $searchEnd = $group->end_date->copy()->startOfDay();
        }
        if ($searchEnd->lt($searchStart)) {
            return 0;
        }

        $schedules = $group->schedules()->whereIn('status', ['active', 'replaced'])
            ->whereDate('valid_from', '<=', $searchEnd)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $searchStart))
            ->orderBy('start_time')->orderBy('id')->get()->groupBy('weekday');

        $created = 0;
        foreach (CarbonPeriod::create($searchStart, $searchEnd) as $date) {
            foreach ($this->schedulesForDate($schedules, $date) as $schedule) {
                $occurrence = Carbon::parse($date->toDateString().' '.$schedule->start_time, config('app.timezone'));
                if ($occurrence->lt($reference)) {
                    continue;
                }

                $inserted = DB::table('class_sessions')->insertOrIgnore([
                    'class_group_id' => $group->id,
                    'class_schedule_id' => $schedule->id,
                    'session_date' => $date->toDateString(),
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'planned_teacher_id' => $schedule->teacher_id ?: $group->main_teacher_id,
                    'status' => 'planned',
                    'attendance_state' => 'open',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created += $inserted;
                if ($created >= $needed) {
                    return $created;
                }
            }
        }

        return $created;
    }

    /** @param Collection<int, Collection<int, ClassSchedule>> $schedules */
    private function schedulesForDate(Collection $schedules, Carbon $date): Collection
    {
        return $schedules->get($date->dayOfWeekIso, collect())->filter(fn (ClassSchedule $schedule) => $date->gte($schedule->valid_from)
            && ($schedule->valid_until === null || $date->lte($schedule->valid_until)));
    }
}

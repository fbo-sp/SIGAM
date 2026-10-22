<?php

namespace App\Services\Attendance;

use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class SessionGenerator
{
    public function generate(Carbon $from, Carbon $until, ?int $associationId = null): int
    {
        $created = 0;
        $schedules = ClassSchedule::query()->whereIn('status', ['active', 'replaced'])->where('valid_from', '<=', $until)->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $from))->whereHas('classGroup', function ($query) use ($associationId, $from, $until): void {
            $query->where('status', 'active')->where('start_date', '<=', $until)->where(fn ($dates) => $dates->whereNull('end_date')->orWhere('end_date', '>=', $from));
            if ($associationId !== null) {
                $query->where('association_id', $associationId);
            }
        })->with('classGroup')->get();

        foreach ($schedules as $schedule) {
            $created += $this->forSchedule($schedule, $from, $until);
        }

        return $created;
    }

    /** Gera as aulas de uma turma no período, inclusive passadas, pelos horários vigentes em cada data. */
    public function generateForGroup(ClassGroup $group, Carbon $from, Carbon $until): int
    {
        return $group->schedules()->whereIn('status', ['active', 'replaced'])->whereDate('valid_from', '<=', $until)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))->get()
            ->sum(fn (ClassSchedule $schedule) => $this->forSchedule($schedule->setRelation('classGroup', $group), $from, $until));
    }

    private function forSchedule(ClassSchedule $schedule, Carbon $from, Carbon $until): int
    {
        $created = 0;
        foreach (CarbonPeriod::create($from, $until) as $date) {
            if ($date->dayOfWeekIso !== $schedule->weekday || $date->lt($schedule->valid_from) || ($schedule->valid_until && $date->gt($schedule->valid_until)) || $date->lt($schedule->classGroup->start_date) || ($schedule->classGroup->end_date && $date->gt($schedule->classGroup->end_date))) {
                continue;
            }
            $created += DB::table('class_sessions')->insertOrIgnore([
                'class_group_id' => $schedule->class_group_id,
                'class_schedule_id' => $schedule->id,
                'session_date' => $date->toDateString(),
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'planned_teacher_id' => $schedule->teacher_id ?: $schedule->classGroup->main_teacher_id,
                'status' => 'planned',
                'attendance_state' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $created;
    }
}

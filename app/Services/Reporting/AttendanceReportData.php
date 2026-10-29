<?php

namespace App\Services\Reporting;

use App\Models\AttendanceRecord;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\TeacherProfile;
use App\Services\Progression\SessionTechniqueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Base dos relatórios de frequência (3 e 4): cada aula passada da turma, no período, para cada aluno matriculado
 * naquela data. Só chamada finalizada conta na frequência (decisão de Fabio em 28/10/2026); aula com chamada aberta
 * aparece à parte. Aulas canceladas ou reagendadas ficam fora; visitantes também (não são matrícula da turma).
 */
class AttendanceReportData
{
    public const MAX_DAYS = 366;

    /**
     * @param  array{dojo_id: ?int, class_group_id: ?int, from: CarbonImmutable, to: CarbonImmutable}  $filters
     * @return Collection<int, ClassGroup>
     */
    public function classes(ReportContext $context, array $filters): Collection
    {
        return $context->classes
            ->when($filters['dojo_id'], fn (Collection $classes) => $classes->where('dojo_id', $filters['dojo_id']))
            ->when($filters['class_group_id'], fn (Collection $classes) => $classes->where('id', $filters['class_group_id']))
            ->keyBy('id');
    }

    /**
     * @param  array{dojo_id: ?int, class_group_id: ?int, from: CarbonImmutable, to: CarbonImmutable}  $filters
     * @return array{entries: Collection<int, array{enrollment: ClassEnrollment, session: ClassSession, class: ClassGroup, record: ?AttendanceRecord}>, sessions: Collection<int, ClassSession>, teachers: Collection<int, TeacherProfile>}
     */
    public function build(ReportContext $context, array $filters): array
    {
        $classes = $this->classes($context, $filters);
        $from = $filters['from']->toDateString();
        $to = ($filters['to']->lt(CarbonImmutable::today()) ? $filters['to'] : CarbonImmutable::today())->toDateString();
        if ($classes->isEmpty() || $from > $to) {
            return ['entries' => collect(), 'sessions' => collect(), 'teachers' => collect()];
        }

        $sessions = ClassSession::query()->whereIn('class_group_id', $classes->keys())->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->whereDate('session_date', '>=', $from)->whereDate('session_date', '<=', $to)
            ->with('staff:id,class_session_id,teacher_profile_id,role')->orderBy('session_date')->orderBy('start_time')->get();
        $enrollments = ClassEnrollment::query()->whereIn('class_group_id', $classes->keys())->whereIn('status', ['active', 'ended'])
            ->whereDate('start_date', '<=', $to)->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->with(['student:id,person_id,registration_number', 'student.person:id,full_name,social_name',
                'student.martialArts' => fn ($arts) => $arts->where('status', 'active')->with('currentRank:id,display_name')])->get();
        $records = AttendanceRecord::query()->whereIn('class_session_id', $sessions->pluck('id'))->where('participation_type', 'regular')
            ->get(['class_session_id', 'student_id', 'status'])->keyBy(fn (AttendanceRecord $record) => $record->class_session_id.'-'.$record->student_id);
        $teacherIds = $sessions->flatMap(fn (ClassSession $session) => $session->staff->pluck('teacher_profile_id')->push($session->planned_teacher_id))
            ->merge($classes->pluck('main_teacher_id'))->filter()->unique();
        $teachers = TeacherProfile::query()->whereIn('id', $teacherIds)->with('person:id,full_name,social_name')->get()->keyBy('id');

        $byClass = $sessions->groupBy('class_group_id');
        $entries = $enrollments->flatMap(fn (ClassEnrollment $enrollment) => ($byClass[$enrollment->class_group_id] ?? collect())
            ->filter(fn (ClassSession $session) => $session->session_date->toDateString() >= $enrollment->start_date->toDateString()
                && ($enrollment->end_date === null || $session->session_date->toDateString() <= $enrollment->end_date->toDateString()))
            ->map(fn (ClassSession $session) => ['enrollment' => $enrollment, 'session' => $session, 'class' => $classes[$enrollment->class_group_id],
                'record' => $records->get($session->id.'-'.$enrollment->student_id)]))
            ->sortBy(fn (array $entry) => [mb_strtolower($this->studentName($entry['enrollment'])), mb_strtolower($entry['class']->name), $entry['session']->session_date->toDateString(), $entry['session']->start_time])
            ->values();

        return ['entries' => $entries, 'sessions' => $sessions, 'teachers' => $teachers];
    }

    public function studentName(ClassEnrollment $enrollment): string
    {
        $person = $enrollment->student->person;

        return $person->social_name ?: $person->full_name;
    }

    public function rank(ClassEnrollment $enrollment, ClassGroup $class): ?string
    {
        return $enrollment->student->martialArts->firstWhere('martial_art_id', $class->martial_art_id)?->currentRank?->display_name;
    }

    public function closed(ClassSession $session): bool
    {
        return $session->attendance_state === 'closed';
    }

    public function present(?AttendanceRecord $record): bool
    {
        return $record !== null && in_array($record->status, SessionTechniqueService::PRESENT_STATUSES, true);
    }

    public function teacherName(?TeacherProfile $teacher): ?string
    {
        return $teacher?->person ? ($teacher->person->social_name ?: $teacher->person->full_name) : null;
    }

    /** @param array{dojo_id: ?int, class_group_id: ?int, from: CarbonImmutable, to: CarbonImmutable} $filters */
    public function describe(ReportContext $context, array $filters): string
    {
        $dojo = $filters['dojo_id'] ? $context->dojos->firstWhere('id', $filters['dojo_id'])->name : ($context->dojo?->name ?? ($context->isTeacher() ? $context->dojos->pluck('name')->implode(', ') : 'todos os dojos'));
        $class = $filters['class_group_id'] ? 'Turma '.$context->classes->firstWhere('id', $filters['class_group_id'])->name : ($context->isTeacher() ? 'as suas turmas' : 'todas as turmas');

        return $dojo.' · '.$class.' · Período '.$filters['from']->format('d/m/Y').' a '.$filters['to']->format('d/m/Y');
    }
}

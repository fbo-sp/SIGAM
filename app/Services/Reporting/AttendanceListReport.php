<?php

namespace App\Services\Reporting;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\AuditService;
use App\Services\Progression\SessionTechniqueService;
use App\Support\Export\PdfReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Relatório 2, lista de presença em PDF (decisões de Fabio em 28/10/2026): a aula com chamada finalizada (SIM/NÃO e
 * totais), a lista em branco da aula para marcar a caneta e a grade do mês da turma.
 */
class AttendanceListReport
{
    private const DAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public function __construct(private readonly AttendanceService $attendance, private readonly AuditService $audit, private readonly PdfReport $pdf) {}

    /** Aula finalizada sai com as respostas; aberta ou futura sai em branco. */
    public function session(Association $association, ClassSession $session, User $user, string $level): Response
    {
        if (in_array($session->status, ['cancelled', 'rescheduled'], true)) {
            throw ValidationException::withMessages(['session' => 'Aula cancelada ou reagendada não tem lista de presença.']);
        }
        $session->loadMissing('classGroup.dojo', 'staff');
        $group = $session->classGroup;
        $closed = $session->attendance_state === 'closed';
        $roster = $this->attendance->roster($session);
        $ranks = $this->ranks($roster->pluck('student'), $group);
        $records = $closed ? $session->records()->get(['student_id', 'status'])->keyBy('student_id') : collect();
        $teachers = TeacherProfile::query()->whereIn('id', $session->staff->pluck('teacher_profile_id')->push($session->planned_teacher_id, $group->main_teacher_id)->filter())
            ->with('person:id,full_name,social_name')->get()->keyBy('id');
        $lead = $session->staff->firstWhere('role', 'lead');
        $planned = $this->name($teachers->get($session->planned_teacher_id ?: $group->main_teacher_id));

        $rows = $roster->map(fn (array $row) => [
            'name' => $this->name($row['student']), 'visitor' => $row['type'] === 'visitor', 'rank' => $ranks[$row['student']->id] ?? null,
            'present' => $closed ? (($status = $records->get($row['student']->id)?->status) === null ? null : in_array($status, SessionTechniqueService::PRESENT_STATUSES, true)) : null,
        ])->sortBy(fn ($row) => [$row['visitor'] ? 1 : 0, mb_strtolower($row['name'])])->values();

        $data = [
            'association' => $association, 'session' => $session, 'group' => $group, 'rows' => $rows,
            'when' => self::DAYS[$session->session_date->dayOfWeek].' '.$session->session_date->format('d/m/Y').' · '.substr((string) $session->start_time, 0, 5),
            'lead' => $lead ? $this->name($teachers->get($lead->teacher_profile_id)) : ($planned ? $planned.' (previsto)' : null),
            'planned' => $planned,
            'assistants' => $session->staff->where('role', 'assistant')->map(fn ($row) => $this->name($teachers->get($row->teacher_profile_id)))->filter()->sort()->implode(', '),
            'closedBy' => $closed && $session->closed_at ? $session->closed_at->format('d/m/Y H:i').(($by = User::query()->find($session->closed_by_user_id)) ? ' por '.$by->name : '') : null,
            'techniques' => TechniqueItem::query()->whereIn('id', ClassSessionTechnique::query()->where('class_session_id', $session->id)->whereNull('removed_at')->pluck('technique_item_id'))
                ->orderBy('name')->pluck('name')->implode(', '),
        ];
        $kind = $closed ? 'finalizada' : 'branco';
        $this->audit->record('report.exported', 'exported', $association, null,
            ['report' => 'attendance_list_'.$kind, 'level' => $level, 'filters' => ['class_session_id' => $session->id], 'rows' => $rows->count()],
            ['association_id' => $association->id, 'dojo_id' => $group->dojo_id]);

        return $this->pdf->download($closed ? 'reports.pdf.attendance-closed' : 'reports.pdf.attendance-blank', $data,
            'lista-presenca-'.$this->slug($group->name).'-'.$session->session_date->format('Y-m-d').'.pdf', $this->footer($user));
    }

    /** Grade do mês: uma coluna por aula da turma no mês, para marcar P ou F. */
    public function grid(ReportContext $context, ClassGroup $group, CarbonImmutable $month): Response
    {
        $sessions = ClassSession::query()->where('class_group_id', $group->id)->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->whereDate('session_date', '>=', $month->startOfMonth()->toDateString())->whereDate('session_date', '<=', $month->endOfMonth()->toDateString())
            ->orderBy('session_date')->orderBy('start_time')->get();
        if ($sessions->isEmpty()) {
            throw ValidationException::withMessages(['mes' => 'Esta turma não tem aulas neste mês. Gere as aulas da turma ou escolha outro mês.']);
        }
        $first = $sessions->first()->session_date->toDateString();
        $last = $sessions->last()->session_date->toDateString();
        $students = ClassEnrollment::query()->where('class_group_id', $group->id)->whereIn('status', ['active', 'ended'])
            ->whereDate('start_date', '<=', $last)->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $first))
            ->with('student.person:id,full_name,social_name')->get()->pluck('student')->unique('id');
        $ranks = $this->ranks($students, $group);
        $rows = $students->map(fn (Student $student) => ['name' => $this->name($student), 'rank' => $ranks[$student->id] ?? null])->sortBy(fn ($row) => mb_strtolower($row['name']))->values();
        $group->loadMissing('dojo');
        $slots = $sessions->map(fn (ClassSession $session) => [$session->session_date->dayOfWeek, substr((string) $session->start_time, 0, 5)])->unique()->sortBy(fn ($slot) => $slot[0] * 10000 + (int) str_replace(':', '', $slot[1]));
        $data = [
            'association' => $context->association, 'group' => $group, 'rows' => $rows,
            'dates' => $sessions->map(fn (ClassSession $session) => $session->session_date->format('d/m'))->all(),
            'monthLabel' => self::MONTHS[$month->month - 1].' de '.$month->year,
            'slots' => $slots->groupBy(1)->map(fn (Collection $days, string $time) => $days->map(fn ($slot) => self::DAYS[$slot[0]])->implode(' e ').' · '.$time)->implode('; '),
            'teacher' => $this->name(TeacherProfile::query()->with('person:id,full_name,social_name')->find($group->main_teacher_id)),
        ];
        $this->audit->record('report.exported', 'exported', $context->association, null,
            ['report' => 'attendance_grid', 'level' => $context->level, 'filters' => ['class_group_id' => $group->id, 'month' => $month->format('Y-m')], 'rows' => $rows->count()],
            ['association_id' => $context->association->id, 'dojo_id' => $group->dojo_id]);

        return $this->pdf->download('reports.pdf.attendance-grid', $data, 'grade-presenca-'.$this->slug($group->name).'-'.$month->format('Y-m').'.pdf',
            $this->footer($context->user), true);
    }

    /** @return array<int, string> faixa atual na modalidade da turma, por aluno */
    private function ranks(Collection $students, ClassGroup $group): array
    {
        return Student::query()->whereIn('id', $students->pluck('id'))->with(['martialArts' => fn ($arts) => $arts->where('martial_art_id', $group->martial_art_id)->where('status', 'active')->with('currentRank:id,display_name')])
            ->get()->mapWithKeys(fn (Student $student) => [$student->id => $student->martialArts->first()?->currentRank?->display_name])->filter()->all();
    }

    private function name(TeacherProfile|Student|null $model): ?string
    {
        $person = $model?->person;

        return $person ? ($person->social_name ?: $person->full_name) : null;
    }

    private function footer(User $user): string
    {
        return 'Gerado em '.now()->format('d/m/Y H:i').' por '.$user->name.' · JUDOMA';
    }

    private function slug(string $text): string
    {
        return Str::slug($text) ?: 'turma';
    }
}

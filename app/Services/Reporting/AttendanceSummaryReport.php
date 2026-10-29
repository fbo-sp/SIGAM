<?php

namespace App\Services\Reporting;

use App\Services\AuditService;
use App\Support\Export\XlsxReport;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Relatório 3, frequência resumida: uma linha por aluno e turma e uma aba com o total por aluno. */
class AttendanceSummaryReport
{
    public function __construct(private readonly AttendanceReportData $data, private readonly AuditService $audit) {}

    /** @return array{by_class: Collection<int, array<int, mixed>>, by_student: Collection<int, array<int, mixed>>, closed: int, open: int} */
    public function rows(ReportContext $context, array $filters): array
    {
        $built = $this->data->build($context, $filters);
        $byClass = $built['entries']->groupBy(fn (array $entry) => $entry['enrollment']->student_id.'-'.$entry['class']->id)->map(function (Collection $entries) {
            $first = $entries->first();
            $closed = $entries->filter(fn (array $entry) => $this->data->closed($entry['session']));
            $present = $closed->filter(fn (array $entry) => $this->data->present($entry['record']))->count();

            return ['student_id' => $first['enrollment']->student_id, 'registration' => $first['enrollment']->student->registration_number, 'name' => $this->data->studentName($first['enrollment']),
                'rank' => $this->data->rank($first['enrollment'], $first['class']), 'class' => $first['class']->name, 'held' => $closed->count(), 'present' => $present,
                'open' => $entries->count() - $closed->count()];
        })->values();

        $byStudent = $byClass->groupBy('student_id')->map(fn (Collection $rows) => [
            $rows->first()['registration'], $rows->first()['name'], $rows->count(), $rows->sum('held'), $rows->sum('present'), $rows->sum('held') - $rows->sum('present'), $this->ratio($rows->sum('present'), $rows->sum('held')),
        ])->values();

        return [
            'by_class' => $byClass->map(fn (array $row) => [$row['registration'], $row['name'], $row['rank'], $row['class'], $row['held'], $row['present'], $row['held'] - $row['present'], $this->ratio($row['present'], $row['held']), $row['open']]),
            'by_student' => $byStudent,
            'closed' => $built['sessions']->filter(fn ($session) => $this->data->closed($session))->count(),
            'open' => $built['sessions']->reject(fn ($session) => $this->data->closed($session))->count(),
        ];
    }

    public function export(ReportContext $context, array $filters): StreamedResponse
    {
        $rows = $this->rows($context, $filters);
        $scope = $this->data->describe($context, $filters);
        $generated = 'Gerado em '.now()->format('d/m/Y H:i').' por '.$context->user->name.' ('.$context->label().') · só aulas com chamada finalizada contam';
        $report = new XlsxReport;
        $sheet = $report->sheet('Por turma', 'Frequência resumida', $context->association->trade_name.' · '.$scope, $generated,
            [['Matrícula', 20], ['Aluno', 28], ['Faixa', 15], ['Turma', 20], ['Aulas no período', 11], ['Presenças', 11], ['Faltas', 9], ['Frequência', 11], ['Chamadas abertas (não contam)', 20]], $rows['by_class']);
        $report->percentColumn($sheet, 'H');
        $sheet = $report->sheet('Por aluno', 'Frequência resumida · total por aluno', $context->association->trade_name.' · '.$scope, $generated,
            [['Matrícula', 20], ['Aluno', 28], ['Turmas', 8], ['Aulas no período', 11], ['Presenças', 11], ['Faltas', 9], ['Frequência', 11]], $rows['by_student']);
        $report->percentColumn($sheet, 'G');
        $report->notes('Sobre', [
            'Aulas no período: aulas da turma com chamada finalizada enquanto o aluno estava matriculado nela.',
            'Presença conta SIM (e registros antigos de reposição ou aula experimental); falta é NÃO ou aluno sem marcação na chamada finalizada.',
            'Chamadas abertas aparecem à parte e não entram na frequência. Aulas canceladas ou reagendadas ficam fora; visitas em outras turmas também.',
            'Cores: verde 75% ou mais, amarelo de 50% a 74%, vermelho abaixo de 50%.',
        ]);
        $this->audit->record('report.exported', 'exported', $context->association, null,
            ['report' => 'attendance_summary', 'level' => $context->level, 'filters' => $this->auditFilters($filters), 'rows' => $rows['by_class']->count()],
            ['association_id' => $context->association->id, 'dojo_id' => $context->dojo?->id]);

        return $report->download('frequencia-resumida-'.$filters['from']->format('Y-m-d').'-a-'.$filters['to']->format('Y-m-d').'.xlsx');
    }

    private function ratio(int $present, int $held): ?float
    {
        return $held === 0 ? null : round($present / $held, 4);
    }

    private function auditFilters(array $filters): array
    {
        return array_filter(['dojo_id' => $filters['dojo_id'], 'class_group_id' => $filters['class_group_id'], 'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()]);
    }
}

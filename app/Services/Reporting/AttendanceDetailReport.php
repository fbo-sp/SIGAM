<?php

namespace App\Services\Reporting;

use App\Models\ClassSession;
use App\Services\AuditService;
use App\Support\Export\XlsxReport;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório 4, frequência detalhada: uma linha por aula de cada aluno, com quem deu a aula e os auxiliares presentes.
 * Aulas fechadas antes do registro de quem deu a aula mostram o professor previsto com "(previsto)".
 */
class AttendanceDetailReport
{
    public const LIMIT = 10000;

    private const DAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function __construct(private readonly AttendanceReportData $data, private readonly AuditService $audit) {}

    /** @return array{rows: Collection<int, array<int, mixed>>, total: int} */
    public function rows(ReportContext $context, array $filters): array
    {
        $built = $this->data->build($context, $filters);
        $teachers = $built['teachers'];
        $staff = $built['sessions']->mapWithKeys(function (ClassSession $session) use ($teachers) {
            $lead = $session->staff->firstWhere('role', 'lead');
            $teacher = $lead ? $this->data->teacherName($teachers->get($lead->teacher_profile_id)) : null;

            return [$session->id => [
                'lead' => $teacher,
                'planned' => $session->planned_teacher_id,
                'assistants' => $session->staff->where('role', 'assistant')->map(fn ($row) => $this->data->teacherName($teachers->get($row->teacher_profile_id)))->filter()->sort()->implode(', '),
            ]];
        });

        $rows = $built['entries']->take(self::LIMIT)->map(function (array $entry) use ($staff, $teachers) {
            $session = $entry['session'];
            $info = $staff[$session->id];
            $teacher = $info['lead'];
            if ($teacher === null) {
                $planned = $this->data->teacherName($teachers->get($info['planned'] ?: $entry['class']->main_teacher_id));
                $teacher = $planned ? $planned.' (previsto)' : null;
            }
            $closed = $this->data->closed($session);
            $presence = ! $closed ? '—' : ($entry['record'] === null ? 'Sem marcação' : ($this->data->present($entry['record']) ? 'SIM' : 'NÃO'));

            return [$entry['enrollment']->student->registration_number, $this->data->studentName($entry['enrollment']), $entry['class']->name, $session->session_date,
                self::DAYS[$session->session_date->dayOfWeek], substr((string) $session->start_time, 0, 5), $teacher, $info['assistants'] ?: null, $presence, $closed ? 'Finalizada' : 'Aberta'];
        });

        return ['rows' => $rows, 'total' => $built['entries']->count()];
    }

    public function export(ReportContext $context, array $filters): StreamedResponse
    {
        $result = $this->rows($context, $filters);
        $subtitle = $context->association->trade_name.' · '.$this->data->describe($context, $filters);
        if ($result['total'] > self::LIMIT) {
            $subtitle .= ' · mostrando as primeiras '.self::LIMIT.' de '.$result['total'].' linhas; filtre por turma ou período';
        }
        $report = new XlsxReport;
        $sheet = $report->sheet('Aula a aula', 'Frequência detalhada', $subtitle, 'Gerado em '.now()->format('d/m/Y H:i').' por '.$context->user->name.' ('.$context->label().')',
            [['Matrícula', 20], ['Aluno', 28], ['Turma', 20], ['Data', 11], ['Dia', 6], ['Início', 7], ['Professor da aula', 30], ['Auxiliares presentes', 24], ['Presença', 12], ['Chamada', 12]], $result['rows']);
        $report->valueColors($sheet, 'I', ['SIM' => 'FFD9F2E3', 'NÃO' => 'FFF8D7DA']);
        $report->notes('Sobre', [
            'Uma linha por aula de cada aluno matriculado na turma na data da aula. Aulas canceladas ou reagendadas ficam fora.',
            'Aulas antes de o JUDOMA registrar quem deu a aula mostram o professor previsto, com "(previsto)".',
            'Chamada aberta aparece com "—" e não conta na frequência. "Sem marcação" é aluno sem SIM ou NÃO numa chamada finalizada.',
        ]);
        $this->audit->record('report.exported', 'exported', $context->association, null,
            ['report' => 'attendance_detail', 'level' => $context->level, 'filters' => array_filter(['dojo_id' => $filters['dojo_id'], 'class_group_id' => $filters['class_group_id'],
                'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()]), 'rows' => $result['rows']->count()],
            ['association_id' => $context->association->id, 'dojo_id' => $context->dojo?->id]);

        return $report->download('frequencia-detalhada-'.$filters['from']->format('Y-m-d').'-a-'.$filters['to']->format('Y-m-d').'.xlsx');
    }
}

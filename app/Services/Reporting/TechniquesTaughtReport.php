<?php

namespace App\Services\Reporting;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Services\AuditService;
use App\Services\Progression\CurriculumCoverageService;
use App\Support\Export\XlsxReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório 5, técnicas ministradas na turma: resumo com indicadores e dois gráficos nativos do Excel, técnicas aula
 * a aula e o currículo da próxima faixa dos alunos que não apareceu no período (decisão de Fabio em 28/10/2026).
 * Só aulas com chamada finalizada contam, como na frequência.
 */
class TechniquesTaughtReport
{
    private const GROUP_ROW = 12;

    public function __construct(private readonly CurriculumCoverageService $coverage, private readonly AuditService $audit) {}

    /** @return array{sessions: Collection, groups: Collection, items: Collection, missing: Collection, kpis: list<array{0: string, 1: int|float|null}>} */
    public function data(ReportContext $context, ClassGroup $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // O serviço de cobertura avança meses com datas mutáveis.
        $coverage = $this->coverage->forClass($context->association, $group, Carbon::parse($from->toDateString()), Carbon::parse($to->toDateString()), closedOnly: true);
        $sessions = $coverage['session_list'];
        $axes = $coverage['axes'];
        $bySession = $coverage['marks']->groupBy('technique_item_id')->map(fn (Collection $marks) => $marks->pluck('class_session_id')->unique()->values());
        $dates = $sessions->mapWithKeys(fn (ClassSession $session) => [$session->id => $session->session_date]);
        $groupKey = fn (int $itemId) => ($axes['type'][$itemId] ?? 'practical').'|'.($axes['axis'][$itemId] ?? '');

        // Quantos alunos e quais faixas pedem cada item (currículo acumulado da próxima faixa de cada aluno).
        $needs = [];
        foreach ($coverage['ranks'] as $rank) {
            foreach ($rank['rows'] as $row) {
                $needs[$row['item']->id]['students'] = ($needs[$row['item']->id]['students'] ?? 0) + $rank['students']->count();
                $needs[$row['item']->id]['ranks'][] = $rank['rank']->display_name;
            }
        }
        $curriculum = $coverage['overall']['rows']->keyBy(fn (array $row) => $row['item']->id);
        $itemIds = $curriculum->keys()->merge($bySession->keys()->filter(fn ($id) => isset($axes['items'][$id])))->unique();
        $paths = $curriculum->map(fn (array $row) => $row['path'] ?? $row['item']->name);
        $items = $itemIds->map(fn (int $id) => [
            'id' => $id, 'key' => $groupKey($id), 'group' => $axes['axis'][$id] ?? '', 'name' => $axes['items'][$id]->name.($axes['items'][$id]->kind === 'group' ? ' (grupo inteiro)' : ''),
            'path' => $paths[$id] ?? $axes['items'][$id]->name, 'sessions' => $bySession[$id] ?? collect(), 'in_curriculum' => $curriculum->has($id),
            'worked' => isset($bySession[$id]), 'students' => $needs[$id]['students'] ?? 0, 'ranks' => array_values(array_unique($needs[$id]['ranks'] ?? [])),
        ])->sortBy(fn (array $item) => [str_starts_with($item['key'], 'theoretical') ? 1 : 0, mb_strtolower($item['path'])])->values();

        $groups = $items->groupBy('key')->map(fn (Collection $set) => [
            'label' => ($set->first()['group'] ?: 'Outros').(str_starts_with($set->first()['key'], 'theoretical') ? ' (teórico)' : ''),
            'sessions' => $set->flatMap(fn (array $item) => $item['sessions'])->unique()->count(),
            'worked' => $set->where('in_curriculum', true)->where('worked', true)->count(),
            'total' => $set->where('in_curriculum', true)->count(),
        ])->values();

        $missing = $items->where('in_curriculum', true)->where('worked', false)->values();
        $last = ClassSessionTechnique::query()->join('class_sessions', 'class_sessions.id', '=', 'class_session_techniques.class_session_id')
            ->where('class_sessions.class_group_id', $group->id)->whereNull('class_session_techniques.removed_at')->whereIn('class_session_techniques.technique_item_id', $missing->pluck('id'))
            ->groupBy('class_session_techniques.technique_item_id')->selectRaw('class_session_techniques.technique_item_id as item_id, max(class_sessions.session_date) as last_date')
            ->pluck('last_date', 'item_id');
        $missing = $missing->map(fn (array $item) => $item + ['last' => isset($last[$item['id']]) ? CarbonImmutable::parse($last[$item['id']]) : null]);

        $total = $curriculum->count();
        $covered = $curriculum->filter(fn (array $row, int $id) => isset($bySession[$id]))->count();

        return [
            'sessions' => $sessions, 'dates' => $dates, 'groups' => $groups, 'items' => $items, 'missing' => $missing, 'students' => $coverage['students'],
            'kpis' => [
                ['Aulas finalizadas no período', $sessions->count()],
                ['Aulas com técnicas marcadas', $coverage['marks']->pluck('class_session_id')->unique()->count()],
                ['Itens do currículo da turma', $total],
                ['Itens abordados', $covered],
                ['Cobertura do currículo', $total > 0 ? round($covered / $total, 4) : null],
                ['Itens não abordados', $total - $covered],
            ],
        ];
    }

    public function export(ReportContext $context, ClassGroup $group, CarbonImmutable $from, CarbonImmutable $to): StreamedResponse
    {
        $group->loadMissing('dojo');
        $data = $this->data($context, $group, $from, $to);
        $period = $from->format('d/m/Y').' a '.$to->format('d/m/Y');
        $generated = 'Gerado em '.now()->format('d/m/Y H:i').' por '.$context->user->name.' ('.$context->label().') · só aulas com chamada finalizada contam';
        $report = new XlsxReport;

        $summary = $report->blankSheet('Resumo', 'Técnicas e conhecimentos ministrados',
            $context->association->trade_name.' · '.$group->dojo->name.' · Turma '.$group->name.' · Período '.$period.' · currículo da próxima faixa dos alunos', $generated);
        $this->summary($report, $summary, $data);

        $sessionColumns = $data['sessions']->map(fn (ClassSession $session) => [$session->session_date->format('d/m'), 6])->all();
        $sessionCount = max($data['sessions']->count(), 1);
        $rows = $data['items']->map(fn (array $item) => [
            $item['group'], $item['name'], $item['sessions']->count(), round($item['sessions']->count() / $sessionCount, 4),
            $item['sessions']->isEmpty() ? null : $item['sessions']->map(fn ($id) => $data['dates'][$id])->min(),
            $item['sessions']->isEmpty() ? null : $item['sessions']->map(fn ($id) => $data['dates'][$id])->max(),
            $item['in_curriculum'] ? $item['students'] : null,
            ...$data['sessions']->map(fn (ClassSession $session) => $item['sessions']->contains($session->id) ? '■' : null)->all(),
        ]);
        $byItem = $report->sheet('Por técnica', 'Técnicas por aula', $group->dojo->name.' · Turma '.$group->name.' · '.$period.' · cada coluna de data é uma aula (■ = técnica trabalhada)',
            $generated, [['Grupo', 18], ['Técnica', 28], ['Aulas', 8], ['% das aulas', 10], ['Primeira', 11], ['Última', 11], ['Alunos que precisam', 12], ...$sessionColumns], $rows);
        $byItem->getStyle('D6:D'.max(6, $byItem->getHighestRow()))->getNumberFormat()->setFormatCode('0%');
        $this->paintMarks($byItem, 8, $data['sessions']->count());

        $report->sheet('Não abordadas', 'Currículo não abordado no período', 'Turma '.$group->name.' · '.$period.' · comparado com o currículo da próxima faixa de cada aluno', $generated,
            [['Grupo', 18], ['Técnica ou conhecimento', 30], ['Faixas que pedem', 26], ['Alunos da turma que precisam', 18], ['Última vez em aula (qualquer período)', 22]],
            $data['missing']->map(fn (array $item) => [$item['group'], $item['name'], implode(', ', $item['ranks']), $item['students'], $item['last'] ?? 'Nunca']));
        $report->notes('Sobre', [
            'Só aulas com chamada finalizada no período contam. Uma técnica conta numa aula quando foi marcada em "Técnicas trabalhadas".',
            'O currículo da turma é a soma do currículo acumulado da próxima faixa de cada aluno ativo matriculado hoje; "Alunos que precisam" conta esses alunos.',
            'Grupo marcado inteiro aparece como "(grupo inteiro)". Técnicas fora do currículo aparecem em "Por técnica" sem a coluna de alunos.',
        ]);
        $this->audit->record('report.exported', 'exported', $context->association, null,
            ['report' => 'techniques_taught', 'level' => $context->level, 'filters' => ['class_group_id' => $group->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()], 'rows' => $data['items']->count()],
            ['association_id' => $context->association->id, 'dojo_id' => $group->dojo_id]);

        return $report->download('tecnicas-ministradas-'.Str::slug($group->name).'-'.$from->format('Y-m-d').'-a-'.$to->format('Y-m-d').'.xlsx');
    }

    private function summary(XlsxReport $report, Worksheet $sheet, array $data): void
    {
        foreach ($data['kpis'] as $index => [$label, $value]) {
            $row = 5 + $index;
            $report->cell($sheet, 'A'.$row, $label);
            $report->cell($sheet, 'B'.$row, $value ?? '—');
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $sheet->getStyle('B'.$row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1F3A68');
            if (is_float($value)) {
                $sheet->getStyle('B'.$row)->getNumberFormat()->setFormatCode('0%');
            }
        }
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(18);
        foreach (['C', 'D', 'E'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(16);
        }
        $report->headerRow($sheet, self::GROUP_ROW, ['Grupo', 'Aulas com o grupo', 'Itens abordados', 'Itens no currículo', 'Cobertura']);
        $row = self::GROUP_ROW;
        foreach ($data['groups'] as $group) {
            $row++;
            $report->cell($sheet, 'A'.$row, $group['label']);
            $report->cell($sheet, 'B'.$row, $group['sessions']);
            $report->cell($sheet, 'C'.$row, $group['worked']);
            $report->cell($sheet, 'D'.$row, $group['total']);
            $report->cell($sheet, 'E'.$row, $group['total'] > 0 ? round($group['worked'] / $group['total'], 4) : null);
            // Colunas escondidas para o gráfico empilhado: abordados e não abordados.
            $report->cell($sheet, 'T'.$row, $group['worked']);
            $report->cell($sheet, 'U'.$row, $group['total'] - $group['worked']);
        }
        if ($row === self::GROUP_ROW) {
            $report->cell($sheet, 'A'.($row + 1), 'Nenhuma técnica marcada e nenhum currículo de próxima faixa para os alunos da turma.');

            return;
        }
        $sheet->setCellValue('T'.self::GROUP_ROW, 'Abordados');
        $sheet->setCellValue('U'.self::GROUP_ROW, 'Não abordados');
        $sheet->getColumnDimension('T')->setVisible(false);
        $sheet->getColumnDimension('U')->setVisible(false);
        $report->percentColumn($sheet, 'E');

        $first = self::GROUP_ROW + 1;
        $categories = "'Resumo'!\$A\${$first}:\$A\${$row}";
        $count = $row - self::GROUP_ROW;
        $bars = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Resumo'!\$B\$".self::GROUP_ROW, null, 1)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categories, null, $count)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Resumo'!\$B\${$first}:\$B\${$row}", null, $count)]);
        $bars->setPlotDirection(DataSeries::DIRECTION_BAR);
        $chart = new Chart('aulas_por_grupo', new Title('Aulas com cada grupo'), null, new PlotArea(null, [$bars]));
        $chart->setTopLeftPosition('G2');
        $chart->setBottomRightPosition('P18');
        $sheet->addChart($chart);

        $stacked = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STACKED, [0, 1],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Resumo'!\$T\$".self::GROUP_ROW, null, 1), new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Resumo'!\$U\$".self::GROUP_ROW, null, 1)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categories, null, $count), new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categories, null, $count)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Resumo'!\$T\${$first}:\$T\${$row}", null, $count), new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Resumo'!\$U\${$first}:\$U\${$row}", null, $count)]);
        $stacked->setPlotDirection(DataSeries::DIRECTION_COL);
        $chart = new Chart('itens_por_grupo', new Title('Itens do currículo abordados e não abordados por grupo'), new Legend(Legend::POSITION_BOTTOM, null, false), new PlotArea(null, [$stacked]));
        $chart->setPlotVisibleOnly(false); // os dados vêm das colunas escondidas T e U
        $chart->setTopLeftPosition('G20');
        $chart->setBottomRightPosition('P38');
        $sheet->addChart($chart);
    }

    /** Pinta de azul as células "■" das colunas de aula. */
    private function paintMarks(Worksheet $sheet, int $firstColumn, int $sessions): void
    {
        if ($sessions === 0) {
            return;
        }
        $last = $sheet->getHighestRow();
        for ($column = $firstColumn; $column < $firstColumn + $sessions; $column++) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->getStyle($letter.'6:'.$letter.max(6, $last))->getAlignment()->setHorizontal('center');
            for ($row = 6; $row <= $last; $row++) {
                if ($sheet->getCell($letter.$row)->getValue() === '■') {
                    $style = $sheet->getStyle($letter.$row);
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4A7BC8');
                    $style->getFont()->getColor()->setARGB('FFFFFFFF');
                }
            }
        }
    }
}

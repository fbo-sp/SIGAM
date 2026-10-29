<?php

namespace App\Support\Export;

use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planilha dos relatórios exportáveis: título, linha de filtros e de geração, cabeçalho azul fixo com filtro do Excel.
 * Texto é gravado sempre como texto (nunca vira fórmula); datas viram data do Excel.
 */
class XlsxReport
{
    public const HEADER_ROW = 5;

    private const NAVY = 'FF1F3A68';

    private Spreadsheet $book;

    private bool $first = true;

    /** Extensões que o gravador XLSX usa; o comando de produção informa quando faltam. */
    public static function supported(): bool
    {
        return extension_loaded('zip') && extension_loaded('xmlwriter');
    }

    public function __construct()
    {
        $this->book = new Spreadsheet;
        $this->book->getProperties()->setCreator('JUDOMA');
    }

    /**
     * @param  list<array{0: string, 1: int}>  $columns  título e largura
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function sheet(string $name, string $title, string $subtitle, string $generated, array $columns, iterable $rows): Worksheet
    {
        $sheet = $this->first ? $this->book->getActiveSheet() : $this->book->createSheet();
        $this->first = false;
        $sheet->setTitle(mb_substr($name, 0, 31));
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(self::NAVY);
        $sheet->setCellValueExplicit('A2', $subtitle, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', $generated, DataType::TYPE_STRING);
        $sheet->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setARGB('FF555555');

        foreach (array_values($columns) as $index => [$label, $width]) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.self::HEADER_ROW, $label);
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $header = $sheet->getStyle('A'.self::HEADER_ROW.':'.$last.self::HEADER_ROW);
        $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::NAVY);
        $header->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(30);

        $row = self::HEADER_ROW;
        foreach ($rows as $values) {
            $row++;
            foreach (array_values($values) as $index => $value) {
                $this->put($sheet, Coordinate::stringFromColumnIndex($index + 1).$row, $value);
            }
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF6F8FB');
            }
        }
        $sheet->freezePane('B'.(self::HEADER_ROW + 1));
        $sheet->setAutoFilter('A'.self::HEADER_ROW.':'.$last.max($row, self::HEADER_ROW));

        return $sheet;
    }

    public function notes(string $name, array $lines): void
    {
        $sheet = $this->book->createSheet()->setTitle($name);
        foreach (array_values($lines) as $index => $line) {
            $sheet->setCellValueExplicit('A'.($index + 1), $line, DataType::TYPE_STRING);
        }
        $sheet->getColumnDimension('A')->setWidth(120);
    }

    public function download(string $filename): StreamedResponse
    {
        $this->book->setActiveSheetIndex(0);
        $writer = new Xlsx($this->book);

        return response()->streamDownload(fn () => $writer->save('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function save(string $path): void
    {
        (new Xlsx($this->book))->save($path);
    }

    private function put(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value instanceof CarbonInterface) {
            $sheet->setCellValue($cell, Date::PHPToExcel($value->copy()->startOfDay()));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        } elseif (is_int($value) || is_float($value)) {
            $sheet->setCellValue($cell, $value);
        } elseif ($value !== null && $value !== '') {
            $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
        }
    }
}

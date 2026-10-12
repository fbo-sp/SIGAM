<?php

namespace App\Support\Import;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class SpreadsheetReader
{
    /** @return array<int, array{row_number: int, values: array<string, string>}> */
    public function read(string $path, string $extension, string $sheetName, int $limit = 1000): array
    {
        $rows = strtolower($extension) === 'csv'
            ? $this->csv($path)
            : $this->xlsx($path, $sheetName);

        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => 'A planilha precisa ter um cabeçalho e pelo menos uma linha de dados.']);
        }

        $headers = array_map($this->header(...), array_shift($rows)['values']);
        if (count(array_filter($headers)) !== count(array_unique(array_filter($headers)))) {
            throw ValidationException::withMessages(['file' => 'O cabeçalho possui colunas repetidas.']);
        }
        if (count($rows) > $limit) {
            throw ValidationException::withMessages(['file' => "Envie no máximo {$limit} linhas por arquivo."]);
        }

        return collect($rows)->map(function (array $row) use ($headers): array {
            $values = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $values[$header] = trim((string) ($row['values'][$index] ?? ''));
                }
            }

            return ['row_number' => $row['row_number'], 'values' => $values];
        })->filter(fn (array $row) => collect($row['values'])->contains(fn ($value) => $value !== ''))->values()->all();
    }

    /** @return array<int, array{row_number: int, values: array<int, string>}> */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível abrir o arquivo.']);
        }
        $first = fgets($handle);
        rewind($handle);
        $separator = substr_count((string) $first, ';') >= substr_count((string) $first, ',') ? ';' : ',';
        $rows = [];
        $number = 0;
        while (($values = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            $number++;
            if ($number === 1 && isset($values[0])) {
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]);
            }
            $rows[] = ['row_number' => $number, 'values' => array_map(fn ($value) => (string) $value, $values)];
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, array{row_number: int, values: array<int, string>}> */
    private function xlsx(string $path, string $wantedSheet): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'A leitura de XLSX não está disponível neste servidor. Use o modelo CSV.']);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'O arquivo XLSX está inválido ou corrompido.']);
        }
        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relations = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            $relationshipNodes = $relations->children('http://schemas.openxmlformats.org/package/2006/relationships');
            foreach ($relationshipNodes->Relationship as $relation) {
                $attributes = $relation->attributes();
                $target = ltrim((string) $attributes['Target'], '/');
                $targets[(string) $attributes['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
            $sheetPath = null;
            $workbookNodes = $workbook->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($workbookNodes->sheets->sheet as $sheet) {
                $sheetAttributes = $sheet->attributes();
                $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                if ($this->header((string) $sheetAttributes['name']) === $this->header($wantedSheet)) {
                    $sheetPath = $targets[(string) $relationshipAttributes['id']] ?? null;
                    break;
                }
            }
            if ($sheetPath === null) {
                throw ValidationException::withMessages(['file' => "A aba '{$wantedSheet}' não foi encontrada no arquivo."]);
            }
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $sharedXml = $this->xml($zip, 'xl/sharedStrings.xml');
                $sharedXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach ($sharedXml->xpath('//x:si') ?: [] as $item) {
                    $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $shared[] = implode('', array_map(fn ($node) => (string) $node, $item->xpath('.//x:t') ?: []));
                }
            }
            $sheet = $this->xml($zip, $sheetPath);
            $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = [];
            foreach ($sheet->xpath('//x:sheetData/x:row') ?: [] as $row) {
                $row->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $values = [];
                foreach ($row->xpath('./x:c') ?: [] as $cell) {
                    $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    if (($cell->xpath('./x:f') ?: []) !== []) {
                        throw ValidationException::withMessages(['file' => 'O arquivo não pode conter fórmulas. Cole apenas valores.']);
                    }
                    $cellAttributes = $cell->attributes();
                    preg_match('/^[A-Z]+/', (string) $cellAttributes['r'], $match);
                    $index = $this->columnIndex($match[0] ?? 'A');
                    $type = (string) $cellAttributes['t'];
                    $value = $type === 'inlineStr'
                        ? implode('', array_map(fn ($node) => (string) $node, $cell->xpath('.//x:t') ?: []))
                        : (string) (($cell->xpath('./x:v') ?: [null])[0] ?? '');
                    $values[$index] = $type === 's' ? (string) ($shared[(int) $value] ?? '') : $value;
                }
                if ($values !== []) {
                    ksort($values);
                    $last = max(array_keys($values));
                    $values = array_replace(array_fill(0, $last + 1, ''), $values);
                }
                $rows[] = ['row_number' => (int) $row->attributes()['r'], 'values' => $values];
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false || ($stat['size'] ?? 0) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'O conteúdo do XLSX excede o limite seguro. Divida o arquivo em lotes menores.']);
        }
        $content = $zip->getFromName($name);
        if ($content === false || ($xml = simplexml_load_string($content)) === false) {
            throw ValidationException::withMessages(['file' => 'O arquivo XLSX não possui uma estrutura válida.']);
        }

        return $xml;
    }

    private function header(string $value): string
    {
        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii(trim($value))))), '_');
    }

    private function columnIndex(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return $number - 1;
    }
}

<?php

namespace App\Support\Export;

class CsvWriter
{
    /** Prevent spreadsheet formulas, including prefixes hidden behind whitespace. */
    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $text) ? "'".$text : $text;
    }

    public static function row($stream, array $values, string $separator = ';'): int|false
    {
        return fputcsv($stream, array_map(self::cell(...), $values), $separator, '"', '');
    }
}

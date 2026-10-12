<?php

namespace App\Support\Normalization;

class InputNormalizer
{
    public static function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function digits(mixed $value): ?string
    {
        $value = preg_replace('/\D/', '', (string) $value);

        return $value === '' ? null : $value;
    }

    public static function email(mixed $value): ?string
    {
        $value = self::nullableText($value);

        return $value === null ? null : mb_strtolower($value);
    }
}

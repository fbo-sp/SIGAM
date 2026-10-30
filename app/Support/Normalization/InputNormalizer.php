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

    /** Telefone guardado só com números, mostrado como (11) 98888-0000. Outros tamanhos aparecem como estão. */
    public static function phoneDisplay(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(strlen($value), [10, 11], true) && ctype_digit($value)
            ? '('.substr($value, 0, 2).') '.substr($value, 2, -4).'-'.substr($value, -4) : $value;
    }
}

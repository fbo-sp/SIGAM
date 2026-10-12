<?php

namespace App\Support\Money;

class MoneyFormatter
{
    public static function brl(string|int $amount): string
    {
        $value = (string) $amount;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $cents] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', ltrim($whole, '0') ?: '0');
        $cents = str_pad(substr($cents, 0, 2), 2, '0');

        return ($negative ? '-' : '').$whole.','.$cents;
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cnpj = preg_replace('/\D/', '', (string) $value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            $fail('Informe um CNPJ válido.');

            return;
        }

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (int) $cnpj[$index] * $weight;
            }

            $check = $sum % 11;
            $check = $check < 2 ? 0 : 11 - $check;
            if ($check !== (int) $cnpj[count($weights)]) {
                $fail('Informe um CNPJ válido.');

                return;
            }
        }
    }
}

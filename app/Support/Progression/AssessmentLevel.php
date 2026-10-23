<?php

namespace App\Support\Progression;

/** Escala única de avaliação técnica (requisito D2). */
final class AssessmentLevel
{
    public const NOT_STARTED = 1;

    public const DEVELOPING = 2;

    public const ADEQUATE = 3;

    public const MASTERED = 4;

    /** @return array<int, string> */
    public static function labels(): array
    {
        return [
            self::NOT_STARTED => 'Não iniciado',
            self::DEVELOPING => 'Em desenvolvimento',
            self::ADEQUATE => 'Adequado',
            self::MASTERED => 'Dominado',
        ];
    }

    /** Níveis que podem ser exigidos no currículo de uma faixa. @return array<int, string> */
    public static function requirable(): array
    {
        return array_diff_key(self::labels(), [self::NOT_STARTED => true]);
    }

    public static function label(?int $level): string
    {
        return self::labels()[$level] ?? '—';
    }
}

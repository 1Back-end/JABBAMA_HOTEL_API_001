<?php

namespace App\Enums;

enum DebtorsSummaryResponse: int
{
    case ZERO = 0;

    public static function values(): array
    {
        return [
            'jour'  => self::ZERO->value,
            'mois'  => self::ZERO->value,
            'annee' => self::ZERO->value,
        ];
    }
}

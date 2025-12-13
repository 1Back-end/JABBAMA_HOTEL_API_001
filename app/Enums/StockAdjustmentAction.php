<?php

namespace App\Enums;

enum StockAdjustmentAction: int
{
    case AVARIE           = 1;
    case DEDUCTION        = 2;
    case AJUSTEMENT_PLUS  = 3;
    case AJUSTEMENT_MOINS = 4;

    public static function TO_ARRAY(): array
    {
        return [
            self::AVARIE->value           => 'AVARIE',
            self::DEDUCTION->value        => 'DEDUCTION',
            self::AJUSTEMENT_PLUS->value  => 'AJUSTEMENT POSITIF',
            self::AJUSTEMENT_MOINS->value => 'AJUSTEMENT NEGATIF',
        ];
    }

    public static function LABEL(int $value): string
    {
        return match ($value) {
            self::AVARIE->value           => 'AVARIE',
            self::DEDUCTION->value        => 'DEDUCTION',
            self::AJUSTEMENT_PLUS->value  => 'AJUSTEMENT POSITIF',
            self::AJUSTEMENT_MOINS->value => 'AJUSTEMENT NEGATIF',
            default => 'INCONNU',
        };
    }
}

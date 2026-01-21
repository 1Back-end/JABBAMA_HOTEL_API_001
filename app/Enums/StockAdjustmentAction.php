<?php

namespace App\Enums;

enum StockAdjustmentAction: int
{
    case AVARIE           = 1;
    case AJUSTEMENT_PLUS  = 3;
    case AJUSTEMENT_MOINS = 4;

    public static function TO_ARRAY(): array
    {
        return [
            self::AVARIE->value           => 'Avarie',
            self::AJUSTEMENT_PLUS->value  => 'Augmentation',
            self::AJUSTEMENT_MOINS->value => 'Suppression',
        ];
    }

    public static function LABEL(int $value): string
    {
        return match ($value) {
            self::AVARIE->value           => 'Avarie',
            self::AJUSTEMENT_PLUS->value  => 'Augmentation',
            self::AJUSTEMENT_MOINS->value => 'Suppression',
            default => 'Inconnu',
        };
    }
    public static function getBadgeClassAdjustment(string $label): string {
        return match(strtoupper($label)) {
            'AVARIE'       => 'badge-avaries',
            'AUGMENTATION' => 'badge-augmentation',
            'SUPPRESSION'  => 'badge-suppression',
            default        => 'badge-secondary',
        };
    }




}

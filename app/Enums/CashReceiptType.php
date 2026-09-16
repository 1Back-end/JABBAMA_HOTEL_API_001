<?php

namespace App\Enums;

enum CashReceiptType: string
{
    case CONSOMMATION_BAR = 'CONSOMMATION BAR';
    case CONSOMMATION_RESTAURANT = 'CONSOMMATION RESTAURANT';
    case CONSOMMATION_BAR_RESTAURANT = 'CONSOMMATION BAR / RESTAURANT';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

enum RestaurantSummaryMode: string
{
    case ZERO_ON_EMPTY = 'zero_on_empty';
    case ALLOW_CUMULATIVE = 'allow_cumulative';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

enum RestaurantExpenseSlug: string
{
    case Bar = 'BAR';
    case Resto = 'RESTO';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

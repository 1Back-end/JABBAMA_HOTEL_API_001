<?php

namespace App\Enums;

enum RestaurantRubricEnum: string
{
    case DIVERS_RESTAURANT = 'DIVERS RESTAURANT';
    case ROOM_SERVICE = 'ROOM SERVICE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

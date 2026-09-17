<?php

namespace App\Enums;

class RestaurantSummaryResponse
{
    public const EMPTY = [
        'chiffre_affaire' => 0,
        'encaissement'    => 0,
        'depenses'        => 0,
        'solde'           => 0,
    ];

    public static function values(): array
    {
        return self::EMPTY;
    }
}

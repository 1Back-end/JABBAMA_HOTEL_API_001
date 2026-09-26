<?php

namespace App\Enums;

enum PaymentLineType: string
{
    case ENCAISSEMENT = 'encaissement';
    case RECOUVREMENT = 'recouvrement';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

enum PaymentRegulationSlug: string
{
    case ENCAISSEMENT_RESTO = 'ENCAISSEMENT RESTO';
    case ENCAISSEMENT_BAR   = 'ENCAISSEMENT BAR';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

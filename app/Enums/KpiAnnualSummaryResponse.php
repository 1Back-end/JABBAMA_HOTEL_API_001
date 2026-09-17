<?php

namespace App\Enums;

enum KpiAnnualSummaryResponse: int
{
    case ZERO = 0;

    public static function values(): array
    {
        return [
            'chiffre_affaire_annuel' => self::ZERO->value,
            'encaissement'           => self::ZERO->value,
            'taux_encaissement'      => self::ZERO->value,
            'charges_annuelles'      => self::ZERO->value,
            'taux_depense'           => self::ZERO->value,
            'marge_brute_annuelle'   => self::ZERO->value,
            'taux_marge_brute'       => self::ZERO->value,
        ];
    }
}

<?php

namespace App\Enums;

enum PdgCategory: string
{
    case AUTRES_ENCAISSEMENTS = 'AUTRES ENCAISSEMENTS';
    case AUTRES_DEPENSES     = 'AUTRES DEPENSES';

    /**
     * Libellé lisible pour l'affichage/rapport
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

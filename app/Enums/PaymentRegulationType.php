<?php

namespace App\Enums;

enum PaymentRegulationType: string
{
    case ENCAISSEMENT = 'encaissement';
    case RECOUVREMENT = 'recouvrement';
    case EXPENSE = 'expense';

    /**
     * Retourne les types considérés comme des entrées (encaissements / recouvrements).
     */
    public static function entryTypes(): array
    {
        return [self::ENCAISSEMENT->value, self::RECOUVREMENT->value];
    }
}

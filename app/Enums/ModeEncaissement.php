<?php

namespace App\Enums;

enum ModeEncaissement : int
{
    case ENCAISSEMENT_DIRECT = 1;
    CASE ENCAISSEMENT_DEBITEURS = 2;

    public function label(): string
    {
        return match ($this) {
            self::ENCAISSEMENT_DIRECT =>  'Encaissement Direct',
            self::ENCAISSEMENT_DEBITEURS =>  'Encaissement Débiteurs'
        };
    }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }
    //
}

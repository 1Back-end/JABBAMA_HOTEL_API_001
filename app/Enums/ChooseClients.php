<?php

namespace App\Enums;

enum ChooseClients : int
{
    CASE CLIENT_DEBITEURS = 1;
    CASE CLIENT_HEBERGEMENT = 2;

    public function label(): string
    {
        return match ($this) {
            self::CLIENT_DEBITEURS =>  'Client debiteurs',
            self::CLIENT_HEBERGEMENT =>  'Client hebergements',
        };
    }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }
    //
}

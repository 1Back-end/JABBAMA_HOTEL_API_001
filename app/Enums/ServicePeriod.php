<?php

namespace App\Enums;

enum ServicePeriod : int
{
    case BREAKFAST  = 1;
    case LUNCH  = 2;

    case DINNER = 3;

    public function label(): string
    {
        return match ($this) {
            self::BREAKFAST =>  'Petit dejeuné',
            self::LUNCH =>  'Déjeuner',
            self::DINNER => 'Dîner',
        };
    }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }

    //
}

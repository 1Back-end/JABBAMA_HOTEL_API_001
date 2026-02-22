<?php

namespace App\Enums;


enum StateFacture: int
{
    case CREATE = 1;
    case IN_PROGRESS = 2;
    case PAID = 3;
    case CANCELLED = 4;

    public function label(): string
     {
         return match ($this) {
             self::CREATE => 'Créer',
             self::IN_PROGRESS => 'En cours',
             self::PAID => 'Soldé',
             self::CANCELLED => 'Annulé',
         };
     }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

}

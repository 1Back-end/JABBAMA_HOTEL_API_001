<?php

namespace App\Enums;

enum HistoricsEncaissementsOrRecouvrements:string
{
    case ENCAISSEMENTS = 'encaissements';
    case RECOVERED = 'recovered';

    public function label(): string
    {
        return match ($this) {
            self::ENCAISSEMENTS => 'Encaissements',
            self::RECOVERED => 'Recouvrements',
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

<?php

namespace App\Enums;

enum MainCouranteFilter:string
{
    case DAY = 'day';
    case INTERVAL = 'interval';

    public function label(): string
    {
        return match ($this) {
            self::DAY => 'Par jour',
            self::INTERVAL => 'Par intervalle',
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

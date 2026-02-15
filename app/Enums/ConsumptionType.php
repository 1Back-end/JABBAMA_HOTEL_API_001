<?php

namespace App\Enums;

enum ConsumptionType: string
{
    case DINE_IN = 'dine_in';
    case TAKE_AWAY = 'take_away';

    public function label(): string
    {
        return match ($this) {
            self::DINE_IN   => 'Sur place',
            self::TAKE_AWAY => 'À emporter',
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
    //
}

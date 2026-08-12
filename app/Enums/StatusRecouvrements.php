<?php

namespace App\Enums;

enum StatusRecouvrements: string
{
    case REGLE = 'recovered';
    case NON_REGLE = 'unrecovered';

    public function label(): string
    {
        return match ($this) {
            self::REGLE => 'Recouvrement ok',
            self::NON_REGLE => 'Recouvrement non ok',
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

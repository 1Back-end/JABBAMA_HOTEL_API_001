<?php

namespace App\Enums;

enum MenuComplementType: string
{
    case COMPLEMENT = 'complement';
    case BOISSON = 'boisson';

    public function label(): string
    {
        return match ($this) {
            self::COMPLEMENT => 'Complément',
            self::BOISSON => 'Boisson chaude',
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

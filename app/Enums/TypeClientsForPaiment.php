<?php

namespace App\Enums;

enum TypeClientsForPaiment : string
{

    case DEBTOR     = 'debtor';
    case PARTNER    = 'partner';
    case FREE       = 'free';

    public function label(): string
    {
        return match ($this) {
            self::DEBTOR  => 'Clients débiteurs',
            self::PARTNER => 'Clients partenaires',
            self::FREE    => 'Gratuit',
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

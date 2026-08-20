<?php

namespace App\Enums;

enum RoomServiceEnum: string
{
    CASE YES  = 'yes';
    case NO     = 'no';

    public function label(): string
    {
        return match ($this) {
            self::YES   => 'Oui',
            self::NO => 'Non',
        };
    }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? '';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }


}

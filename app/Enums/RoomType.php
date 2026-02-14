<?php

namespace App\Enums;

enum RoomType: string
{
    CASE SIMPLE  = 'simple';
    case VIP     = 'vip';

    public function label(): string
    {
        return match ($this) {
            self::SIMPLE   => 'Chambre simple',
            self::VIP => 'Chambre vip',
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

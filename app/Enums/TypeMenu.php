<?php

namespace App\Enums;

enum TypeMenu: string
{
    case MENU = 'MENU';
    case COMPLEMENT = 'COMPLEMENT';
    case HOT_DRINK = 'HOT DRINK';

    public static function safeLabel(?string $value): string
    {
        return match ($value) {
            self::MENU->value => 'MENU',
            self::COMPLEMENT->value => 'COMPLEMENT',
            self::HOT_DRINK->value => 'HOT DRINK',
            default => 'UNKNOWN',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

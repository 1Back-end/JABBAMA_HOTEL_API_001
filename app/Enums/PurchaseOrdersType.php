<?php

namespace App\Enums;

enum PurchaseOrdersType: string
{

    CASE INTERNAL = 'internal';
    CASE EXTERNAL = 'external';

    public function label(): string
    {
        return match ($this) {
            self::INTERNAL => 'Interne',
            self::EXTERNAL => 'Externe',
        };
    }
    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }
    //
}

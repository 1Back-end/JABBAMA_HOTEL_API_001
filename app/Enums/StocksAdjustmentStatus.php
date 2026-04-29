<?php

namespace App\Enums;

enum StocksAdjustmentStatus: string
{
    CASE PENDING  = 'pending';

    CASE VALIDATED = 'validated';

    CASE CANCELLED  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING =>  'En attente',
            self::VALIDATED =>  'Validée',
            self::CANCELLED =>  'Annulée',
        };
    }
    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }
    //
}

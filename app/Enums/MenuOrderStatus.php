<?php

namespace App\Enums;

enum MenuOrderStatus : string
{
    case PENDING    = 'pending';
    case VALIDATED  = 'validated';
    case CANCELLED  = 'cancelled';
    case REJECTED   = 'rejected';
    case COMPLETED  = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'En attente',
            self::VALIDATED => 'Validé',
            self::CANCELLED => 'Annulé',
            self::REJECTED  => 'Rejeté',
            self::COMPLETED => 'Terminé',
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

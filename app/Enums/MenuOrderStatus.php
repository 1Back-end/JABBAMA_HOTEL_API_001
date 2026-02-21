<?php

namespace App\Enums;

enum MenuOrderStatus : string
{
    case PENDING    = 'pending';
    case TRANSFERED = 'transfered';
    case VALIDATED  = 'validated';
    case CANCELLED  = 'cancelled';
    case REJECTED   = 'rejected';
    case COMPLETED  = 'completed';
    case PARTIAL_COMPLETED = 'partial_completed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'En attente',
            self::VALIDATED => 'Validée',
            self::CANCELLED => 'Annulée',
            self::REJECTED  => 'Rejetée',
            self::COMPLETED => 'Livrée totalement',
            self::TRANSFERED => 'Transférée',
            self::PARTIAL_COMPLETED => 'Livrée partiellement',
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

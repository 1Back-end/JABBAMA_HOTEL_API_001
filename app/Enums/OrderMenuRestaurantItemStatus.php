<?php

namespace App\Enums;

enum OrderMenuRestaurantItemStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case NOT_DELIVERED = 'not_delivered';

    case PARTIAL_DELIVERED = 'partial_delivered';

    CASE DELIVERED_IN_PREPARATION = 'delivered_in_preparation';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::DELIVERED => 'Servie totalement',
            self::NOT_DELIVERED => 'Non servie',
            self::PARTIAL_DELIVERED => 'Partiellement servie',
            self::DELIVERED_IN_PREPARATION => 'Prêt pour service'
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

<?php

namespace App\Enums;

enum OrderMenuRestaurantItemStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case NOT_DELIVERED = 'not_delivered';

    case PARTIAL_DELIVERED = 'partial_delivered';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::DELIVERED => 'Livrée',
            self::NOT_DELIVERED => 'Non livrée',
            self::PARTIAL_DELIVERED => 'Livrée partiellement',
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

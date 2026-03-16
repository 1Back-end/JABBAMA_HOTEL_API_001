<?php

namespace App\Enums;

enum OrderMenuRestaurantItemStatus: string
{
    case PENDING = 'pending';

    CASE REJECTED = 'rejected';
    case DELIVERED = 'delivered';
    case NOT_DELIVERED = 'not_delivered';

    case PARTIAL_DELIVERED = 'partial_delivered';

    CASE DELIVERED_IN_PREPARATION = 'delivered_in_preparation';

    case TRANSFERRED = 'transferred';
    CASE REJECTED_FOR_NEW_UPDATE = 'cancel_for_new_update';

    CASE IN_PREPARATION = 'in_preparation';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::DELIVERED => 'Servie totalement',
            self::NOT_DELIVERED => 'Non servie',
            self::PARTIAL_DELIVERED => 'Servie partiellement',
            self::DELIVERED_IN_PREPARATION => 'Prêt pour service',
            self::REJECTED => 'Rejetté',
            self::TRANSFERRED => 'Transférée',
            self::REJECTED_FOR_NEW_UPDATE => 'Rejetée pour ajustement',
            self::IN_PREPARATION => 'En cours de préparation',
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

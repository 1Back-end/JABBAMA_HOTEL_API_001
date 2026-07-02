<?php

namespace App\Enums;

enum PaymentOrderItemStatus: string
{
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case NOT_PAID = 'not_paid';

    public function label(): string
    {
        return match ($this) {
            self::PARTIALLY_PAID => 'Encaissé partiellement',
            self::PAID           => 'Encaissé',
            self::NOT_PAID       => 'Non encaissé',
        };
    }

    public static function safeLabel(?string $value): string
    {
        if ($value === null) {
            return 'Inconnu';
        }

        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}

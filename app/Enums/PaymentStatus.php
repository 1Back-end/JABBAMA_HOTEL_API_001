<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIALLY_PAID = 'partial';
    case PAID = 'paid';

    public function label(): string
    {
        return match($this) {
            self::UNPAID => 'Non payé',
            self::PARTIALLY_PAID => 'Partiel',
            self::PAID => 'Payé',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::UNPAID => 'danger',
            self::PARTIALLY_PAID => 'warning',
            self::PAID => 'success',
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

<?php

namespace App\Enums;

enum CashRegisterFilterType: string
{
    case PAYMENT_METHOD = 'payment_method';
    case CASHIER_AGENT = 'cashier_agent';
    case PAYMENT_TYPE = 'payment_type';

    case EXPENSE_TYPE = 'expense_type';

    public function label(): string
    {
        return match ($this) {
            self::PAYMENT_METHOD => 'Mode de règlement',
            self::CASHIER_AGENT  => 'Par agent',
            self::PAYMENT_TYPE   => 'Type d’encaissement',
            self::EXPENSE_TYPE   => 'Type de dépenses',
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

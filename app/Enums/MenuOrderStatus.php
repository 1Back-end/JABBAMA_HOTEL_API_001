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

    CASE IN_PREPARATION = 'in_preparation';

    CASE PENDING_VALIDATION = 'pending_validation';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'En attente',
            self::VALIDATED => 'Validée',
            self::CANCELLED => 'Annulée',
            self::REJECTED  => 'Rejetée',
            self::COMPLETED => 'Servie totalement',
            self::TRANSFERED => 'Transférée',
            self::PARTIAL_COMPLETED => 'Servie partiellement',
            self::PENDING_VALIDATION => 'En attente validation',
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
    //
}

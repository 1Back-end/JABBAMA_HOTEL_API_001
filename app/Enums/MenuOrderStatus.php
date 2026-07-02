<?php

namespace App\Enums;

enum MenuOrderStatus : string
{
    case TRANSFERRED = 'transferred';
    case CANCELLED  = 'cancelled';
    case REJECTED   = 'rejected';
    case PARTIAL_COMPLETED = 'partial_completed';

    case IN_PREPARATION = 'in_preparation';

    case REJECTED_FOR_NEW_UPDATE = 'cancel_for_new_update';
    case DELIVERED = 'delivered';
    case PARTIAL_DELIVERED = 'partial_delivered';

    case TOTAL_DELIVERED = 'ready';
    case DEFECTIVE = 'defective';
    case REJECTED_AFTER_VALIDATION = 'rejected_after_validation';
    case FACTURATE = 'facture';
    case PAID = 'paid';



    public function label(): string
    {
        return match ($this) {
            self::CANCELLED => 'Annulée',
            self::REJECTED  => 'Rejeté',
            self::TRANSFERRED => 'Transférée',
            self::PARTIAL_COMPLETED => 'Prêt partiellement',
            self::IN_PREPARATION => 'En cours de préparation',
            self::REJECTED_FOR_NEW_UPDATE => 'Rejet du prêt',
            self::DELIVERED => 'Servie',
            self::PARTIAL_DELIVERED => 'Servie partiellement',
            self::TOTAL_DELIVERED => 'Prêt',
            self::DEFECTIVE => 'Défectieuse',
            self::REJECTED_AFTER_VALIDATION => 'Rejet du servi(s)',
            self::FACTURATE => 'Facturée',
            self::PAID           => 'Réglée',
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

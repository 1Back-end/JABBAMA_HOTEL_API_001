<?php

namespace App\Enums;

enum MenuOrderStatus : string
{
    case PENDING    = 'pending';
    case TRANSFERRED = 'transferred';
    case VALIDATED  = 'validated';
    case CANCELLED  = 'cancelled';
    case REJECTED   = 'rejected';
    case COMPLETED  = 'completed';
    case PARTIAL_COMPLETED = 'partial_completed';

    case IN_PREPARATION = 'in_preparation';

    case REJECTED_FOR_NEW_UPDATE = 'cancel_for_new_update';
    case DELIVERED = 'delivered';
    case PARTIAL_DELIVERED = 'partial_delivered';

    case PENDING_VALIDATION = 'pending_validation';
    case NEW_REJECTED = 'new_rejected';

    case TOTAL_DELIVERED = 'ready';
    case DEFECTIVE = 'defective';
    case REJECTED_AFTER_VALIDATION = 'rejected_after_validation';

    case FACTURE_GENERATE = 'facture_generate';
    case FACTURATE = 'facture';
    case REINSTATED = 'reinstated';
    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'En attente',
            self::VALIDATED => 'Validée',
            self::CANCELLED => 'Annulée',
            self::REJECTED  => 'Rejetée',
            self::COMPLETED => 'Prêt totalement',
            self::TRANSFERRED => 'Transférée',
            self::PARTIAL_COMPLETED => 'Prêt partiellement',
            self::PENDING_VALIDATION => 'En attente validation',
            self::IN_PREPARATION => 'En cours de préparation',
            self::REJECTED_FOR_NEW_UPDATE => 'Rejet du prêt',
            self::DELIVERED => 'Servie totalement',
            self::PARTIAL_DELIVERED => 'Servie partiellement',
            self::NEW_REJECTED => 'Rejettée',
            self::TOTAL_DELIVERED => 'Prêt',
            self::DEFECTIVE => 'Défectieuse',
            self::REJECTED_AFTER_VALIDATION => 'Rejet du servi(s)',
            self::FACTURE_GENERATE => 'Facture générée',
            self::FACTURATE => 'Facturée',
            self::REINSTATED => 'Restauration'

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

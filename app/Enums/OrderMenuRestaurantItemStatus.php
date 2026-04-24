<?php

namespace App\Enums;

use function Laravel\Prompts\select;

enum OrderMenuRestaurantItemStatus: string
{
    case PENDING = 'pending';

    CASE REJECTED = 'rejected';
    case DELIVERED = 'delivered';

    case TOTAL_DELIVERED = 'ready';
    case NOT_DELIVERED = 'not_delivered';

    case PARTIAL_DELIVERED = 'partial_delivered';

    CASE DELIVERED_IN_PREPARATION = 'delivered_in_preparation';

    case TRANSFERRED = 'transferred';
    case REJECTED_FOR_NEW_UPDATE = 'cancel_for_new_update';
    case REJECTED_AFTER_VALIDATION = 'rejected_after_validation';
    case IN_PREPARATION = 'in_preparation';
    case PARTIAL_COMPLETED = 'partial_completed';
     case NEW_REJECTED = 'new_rejected';
     case DEFECTIVE = 'defective';

     case FACTURE_GENERATE = 'facture_generate';
     case FACTURATE = 'facture';
    case REINSTATED = 'reinstated';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::DELIVERED => 'Servie totalement',
            self::NOT_DELIVERED => 'Non servie',
            self::PARTIAL_DELIVERED => 'Servie partiellement',
            self::DELIVERED_IN_PREPARATION => 'Prêt pour service',
            self::TOTAL_DELIVERED => 'Prêt totalement',
            self::REJECTED => 'Rejetté',
            self::TRANSFERRED => 'Transférée',
            self::REJECTED_FOR_NEW_UPDATE => 'Rejet du prêt',
            self::IN_PREPARATION => 'En cours de préparation',
            self::PARTIAL_COMPLETED => 'Prêt partiellement',
            self::NEW_REJECTED => 'Rejettée',
            self::DEFECTIVE => 'Défectieux',
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
}

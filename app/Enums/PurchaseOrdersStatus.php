<?php

namespace App\Enums;

enum PurchaseOrdersStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';
    case REJECTED = 'rejected';
    case VALIDATED = 'validated';
    case PARTIALLY_CLOSED = 'partially_closed';
    case IN_DISCUSS = 'in_discuss';
    case CANCEL = 'cancel';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'En brouillon',
            self::OPEN => 'Transférée',
            self::CLOSED => 'Clôturée totalement',
            self::REJECTED => 'Rejetée',
            self::VALIDATED => 'En cours de livraison',
            self::PARTIALLY_CLOSED => 'Clôturé partiellement',
            self::IN_DISCUSS => 'En discussion',
            self::CANCEL => 'Annulée',
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

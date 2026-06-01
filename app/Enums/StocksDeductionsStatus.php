<?php

namespace App\Enums;

enum StocksDeductionsStatus: string
{

    case DRAFT = 'draft';
    case PENDING = 'pending';

    case VALIDATED = 'validated';

    case REJECTED = 'rejected';

    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this){
            self::DRAFT => 'En brouillon',
            self::PENDING => 'En attente',
            self::VALIDATED => 'Validée',
            self::REJECTED => 'Rejetté',
            self::CANCELLED => 'Annulée',

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

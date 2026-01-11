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
            self::DRAFT => 'Déduction en brouillon',
            self::PENDING => 'Déduction en attente',
            self::VALIDATED => 'Déduction validée',
            self::REJECTED => 'Déduction rejetté',
            self::CANCELLED => 'Déduction annulée',

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

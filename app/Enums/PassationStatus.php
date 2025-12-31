<?php

namespace App\Enums;

enum PassationStatus: string
{
    CASE PENDING = 'pending';
    CASE CANCEL = 'cancel';

    CASE IN_DISCUSS = 'in_discuss';
    CASE NO_GAP = 'no_gap';
    CASE WITH_GAP = 'with_gap';

    CASE CLOSED = 'closed';

    CASE REJECTED = 'rejected';


    public function label(): string
    {
        return match ($this) {
            self::CANCEL => 'Passation annulée',
            self::IN_DISCUSS => 'Passation en discussion',
            self::NO_GAP => 'Passation sans écart',
            self::WITH_GAP => 'Passation avec écart',
            self::CLOSED => 'Passation fermée',
            self::REJECTED => 'Passation rejettée',
            self::PENDING => 'Passation en brouillon',
        };
    }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }
}

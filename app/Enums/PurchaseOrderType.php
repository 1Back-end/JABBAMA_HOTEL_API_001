<?php

namespace App\Enums;

enum PurchaseOrderType: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';


    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}

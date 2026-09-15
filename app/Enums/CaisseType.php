<?php

namespace App\Enums;

enum CaisseType: string
{
    case CAISSE = 'CAISSE';

    public static function formatLabel(string $name): string
    {
        $cleanName = strtoupper(trim($name));

        return self::CAISSE->value . ' ' . $cleanName;
    }
}

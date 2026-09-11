<?php

namespace App\Enums;

enum ExpenseSlug: string
{
    case DepensesBar = 'DEPENSES BAR';
    case DepensesResto = 'DEPENSES RESTO';

    /**
     * Get all values as a simple array for Eloquent queries.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

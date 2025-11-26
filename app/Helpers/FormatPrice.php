<?php

namespace App\Helpers;

use NumberFormatter;

class FormatPrice
{
    public static function format($amount, $currency = "XAF", $locale = 'fr_FR'): false|string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        // $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        // $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return $formatter->formatCurrency(round($amount, 2), $currency);
    }
}

<?php

namespace App\Enums;

enum MetricKeyResponse: string
{
    case ENCAISSEMENT = 'encaissement';
    case DEPENSES = 'depenses';

    public static function format(self $key, float|int $value = 0): array
    {
        return [
            $key->value => $value,
        ];
    }
}

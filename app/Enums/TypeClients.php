<?php

namespace App\Enums;

enum TypeClients : int
{
    CASE CLIENTS_SIMPLE = 1;

    CASE CLIENTS_DEBITEURS = 2;

    public static function labels(): array
    {
        return [
            self::CLIENTS_SIMPLE->value => 'Clients Simple',
            self::CLIENTS_DEBITEURS->value => 'Clients debiteurs'
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}

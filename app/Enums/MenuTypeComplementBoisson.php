<?php

namespace App\Enums;

enum MenuTypeComplementBoisson : string
{
    case COMPLEMENT = 'complement';
    case BOISSON    = 'boisson';


    public function label(): string
    {
        return match ($this) {
            self::COMPLEMENT => 'Menu avec complément',
            self::BOISSON => 'Menu avec boisson',
        };
    }
    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }
}

<?php

namespace App\Enums;

enum ExpenseTitleEnum: string
{
    case GLOBAL = 'GLOBAL';
    case BAR = 'BAR';
    case RESTO = 'RESTO';
    case AUTRES = 'AUTRES';
    case AUTRE = 'AUTRE';

    public static function fromSlug(?string $slug): self
    {
        if (!$slug) {
            return self::GLOBAL;
        }

        return self::tryFrom(strtoupper($slug)) ?? self::AUTRE;
    }

    public function getExpenseTitle(string $slugLabel): string
    {
        return match($this) {
            self::GLOBAL => 'DEPENSES GLOBAL',
            self::BAR, self::RESTO => 'DEPENSES ' . $slugLabel,
            self::AUTRES, self::AUTRE => 'AUTRES DEPENSES',
        };
    }

    public function getOtherCashInTitle(string $slugLabel): string
    {
        return match($this) {
            self::GLOBAL, self::AUTRES => 'AUTRES ENCAISSEMENTS',
            self::BAR, self::RESTO, self::AUTRE => 'AUTRES ENCAISSEMENTS ' . $slugLabel,
        };
    }

    public function getReceiptTitle(string $slugLabel): string
    {
        return 'ENCAISSEMENT ' . $slugLabel;
    }

    public function getRecouvrementTitle(string $slugLabel): string
    {
        return 'RECOUVREMENTS ' . $slugLabel;
    }
}

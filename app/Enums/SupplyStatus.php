<?php

namespace App\Enums;

enum SupplyStatus: string
{
    case DRAFT               = 'draft';
    case OPEN                = 'open';
    case CANCELLED           = 'cancelled';
    case REJECTED            = 'rejected';
    case VALIDATED           = 'validated';
    case TRANSFERRED         = 'transferred';
    case IN_DISCUSS          = 'in_discuss';
    case PARTIALLY_VALIDATED = 'partially_validated';

    /**
     * Libellé lisible (FR)
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT               => 'En brouillon',
            self::OPEN                => 'Ouvert',
            self::CANCELLED           => 'Annulé',
            self::REJECTED            => 'Rejetée',
            self::VALIDATED           => 'Validation complète',
            self::TRANSFERRED         => 'En cours d\'approvi....',
            self::IN_DISCUSS          => 'En discussion',
            self::PARTIALLY_VALIDATED => 'Validé partiellement',
        };
    }

    /**
     * Couleur Bootstrap (optionnel mais très utile)
     */
    public function badge(): string
    {
        return match ($this) {
            self::DRAFT               => 'secondary',
            self::OPEN                => 'primary',
            self::CANCELLED           => 'danger',
            self::REJECTED            => 'danger',
            self::VALIDATED           => 'success',
            self::TRANSFERRED         => 'warning',
            self::IN_DISCUSS          => 'info',
            self::PARTIALLY_VALIDATED => 'warning',
        };
    }

    /**
     * Sécurité si valeur inconnue
     */
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
}

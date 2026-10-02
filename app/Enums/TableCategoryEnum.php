<?php

namespace App\Enums;

enum TableCategoryEnum: string
{
    case SYSTEM = 'system';
    case CONFIGURATION = 'configuration';
    case CATALOG = 'catalog';
    case SALES = 'sales';
    case STOCK = 'stock';
    case FINANCE = 'finance';
    case AUDIT = 'audit';
    case UNCATEGORIZED = 'uncategorized';

    /**
     * Libellé lisible en français pour l'interface utilisateur.
     */
    public function label(): string
    {
        return match($this) {
            self::SYSTEM => 'Système et Sécurité',
            self::CONFIGURATION => 'Configuration et Paramétrage',
            self::CATALOG => 'Catalogue & Articles',
            self::SALES => 'Ventes et commandes restaurant',
            self::STOCK => 'Stock et Approvisionnement',
            self::FINANCE => 'Finances',
            self::AUDIT => 'Audit & Traçabilité',
            self::UNCATEGORIZED => 'Non catégorisé',
        };
    }

}

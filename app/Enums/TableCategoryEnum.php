<?php

namespace App\Enums;

enum TableCategoryEnum: string
{
    case SYSTEM = 'system';
    case CONFIGURATION = 'configuration';
    case CATALOG = 'catalog';               // Produits, menus, articles, compléments
    case SALES = 'sales';                   // Commandes, ventes, factures, transactions
    case STOCK = 'stock';                   // Approvisionnements, inventaire, mouvements de stock
    case FINANCE = 'finance';               // Caisses, paiements, dépenses, comptabilité
    case AUDIT = 'audit';                   // Historiques, traçabilité, rapports
    case UNCATEGORIZED = 'uncategorized';   // Non classé (par défaut pour les nouvelles tables)

    /**
     * Libellé lisible en français pour l'interface utilisateur.
     */
    public function label(): string
    {
        return match($this) {
            self::SYSTEM => 'Système & Sécurité',
            self::CONFIGURATION => 'Configuration & Paramétrage',
            self::CATALOG => 'Catalogue & Articles',
            self::SALES => 'Ventes & Commandes',
            self::STOCK => 'Stock & Approvisionnement',
            self::FINANCE => 'Finances & Caisse',
            self::AUDIT => 'Audit & Traçabilité',
            self::UNCATEGORIZED => 'Non catégorisé',
        };
    }

}

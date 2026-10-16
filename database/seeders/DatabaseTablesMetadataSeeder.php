<?php

namespace Database\Seeders;

use App\Enums\TableCategoryEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseTablesMetadataSeeder extends Seeder
{
    public function run(): void
    {
        $databaseName = DB::getDatabaseName();

        $firstUser = DB::table('users')->first();
        $firstUserId = $firstUser ? $firstUser->uuid ?? $firstUser->id ?? null : null;

        $manualDefinitions = [
            // ***************** Parametres systemes ***************************
            'roles' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Liste des Rôles Créés',
                'description' => 'Profils d’accès, rôles et niveaux de responsabilité.',
            ],
            'permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Permissions du Système',
                'description' => 'Droits et actions granulaires de l’application.',
            ],
            'users' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Liste des Comptes Utilisateurs',
                'description' => 'Informations d’identification et profils des utilisateurs.',
            ],
            'modules_applications' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Modules Applicatifs',
                'description' => 'Gestion et configuration globale des modules.',
            ],
            'role_has_permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Attribution Rôles-Permissions',
                'description' => 'Association entre rôles et leurs permissions.',
            ],
            'categories_permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Catégories de Permissions',
                'description' => 'Regroupement logique des permissions par domaine.',
            ],
            'model_has_roles' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Attribution Modèles-Rôles',
                'description' => 'Affectation des rôles aux utilisateurs.',
            ],
            'module_permission' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Association Modules-Permissions',
                'description' => 'Liaison entre permissions et modules applicatifs.',
            ],
            'personal_access_tokens' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Jetons d’Accès & Déconnexion (logout_period)',
                'description' => 'Gère les sessions et la durée avant déconnexion automatique sur l\'interface de facturation.',
            ],
            'jobs' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Tâches Asynchrones (Jobs)',
                'description' => 'Gère la file d\'attente et le suivi des tâches de fond.',
            ],
            'countries' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Pays',
                'description' => 'Gère la liste de référence des pays.',
            ],

            // ***************** Libellées / variables Réutilisables ***************************
            'suppliers' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Fournisseurs',
                'description' => 'Gère la liste des fournisseurs et leurs informations de contact.',
            ],
            'warehouses' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Entrepôts',
                'description' => 'Gère la liste des entrepôts et des différents points de stockage.',
            ],
            'produits' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Articles',
                'description' => 'Gère la liste des produits, articles et leur catalogue.',
            ],
            'units' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Unités de Mesure des Articles',
                'description' => 'Unités de mesure des articles (kg, litre, pièce, etc.).',
            ],
            'categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Catégories des Articles',
                'description' => 'Classification des articles par famille.',
            ],
            'category_tree' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Sous-catégories des Articles',
                'description' => 'Arborescence pour affiner le classement des articles.',
            ],
            'menu_categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Catégories de Menus',
                'description' => 'Gère le classement des catégories de menus.',
            ],
            'menus_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Menus du Restaurant',
                'description' => 'Gère les menus du restaurant.',
            ],
            'configurations_complements' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Compléments & Boissons Chaudes',
                'description' => 'Gère les compléments et boissons chaudes.',
            ],
            'restaurant_drink_configurations' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Configurations des Boissons du Bar',
                'description' => 'Gère la liste et les configurations des boissons du bar.',
            ],
            'regulation_methods' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Modes de Règlement',
                'description' => 'Gère les différents modes de règlement disponibles.',
            ],
            'restaurant_tables' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Tables du Restaurant',
                'description' => 'Gère les tables du restaurant.',
            ],
            'room_service' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Étages',
                'description' => 'Gère la configuration du service des étages.',
            ],
            'restaurant_rooms' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Chambres',
                'description' => 'Gère la configuration des chambres.',
            ],
            'restaurant_partners' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Partenaires',
                'description' => 'Gère les clients partenaires du restaurant.',
            ],
            'free_clients_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Clients Gratuits',
                'description' => 'Gère les clients bénéficiant de gratuités dans le restaurant.',
            ],
            'sales_categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Rubriques de Vente',
                'description' => 'Gère les rubriques de facturation des ventes.',
            ],
            'room_services' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Tarifaire du Room Service',
                'description' => 'Gère la tarification du room service.',
            ],
            'cash_receipt_types' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Catégories d’Encaissements',
                'description' => 'Gère les différentes catégories d’encaissement.',
            ],
            'cash_receipt_families' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Sous Catégories d’Encaissements',
                'description' => 'Gère les familles et sous-catégories d’encaissement.',
            ],
            'restaurant_expense_types' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Catégories de Dépenses',
                'description' => 'Gère les différentes catégories de dépenses.',
            ],
            'restaurant_expense_types_families' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Sous Catégories de Dépenses',
                'description' => 'Gère les familles et sous-catégories de dépenses.',
            ],
            'nature_entrepots' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Natures d’Entrepôts',
                'description' => 'Types et natures des zones de stockage.',
            ],
            'settings_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Paramètres de Facturation & Déconnexion',
                'description' => 'Gère les paramètres généraux de facturation (dont logout_period).',
            ],
            'complements_compositions' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Compositions des Compléments',
                'description' => 'Gère la confection des compléments.',
            ],
            'complements_compositions_items' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Éléments des Compositions de Compléments',
                'description' => 'Gère les articles inclus dans les compléments.',
            ],
            'menu_orders' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Commandes de Menus (Config)',
                'description' => 'Gère la confection de menus.',
            ],
            'menu_orders_items' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Éléments des Commandes de Menus',
                'description' => 'Gère les articles inclus dans les menus.',
            ],
            'drink_compositions' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Confections de Boissons',
                'description' => 'Gère la confection des boissons du bar.',
            ],
            'drink_composition_items' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Éléments de Confection de Boissons',
                'description' => 'Gère les articles inclus dans les boissons.',
            ],
            'medias' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Fichiers Multimédias',
                'description' => 'Gère le stockage et la traçabilité des fichiers multimédias.',
            ],

            // ***************** Données utilisées ***************************
            'produit_point' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Quantité d\'articles dans tous les stocks',
                'description' => 'Gère les stocks et la disponibilité des produits pour chaque entrepôt ou point de vente.',
            ],
            'purchase_orders' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Commandes d\'Articles',
                'description' => 'Gère les commandes d\'achat pour la gestion des stocks.',
            ],
            'supplies' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Approvisionnements',
                'description' => 'Gère les approvisionnements et les entrées de stock.',
            ],
            'stocks_deductions' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Déductions de Stocks',
                'description' => 'Gère les déductions et sorties de stocks.',
            ],
            'passations' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Passations de Stocks',
                'description' => 'Gère les passations et transferts de stocks entre entités.',
            ],
            'stock_adjustments' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Régularisations de Stocks',
                'description' => 'Gère les régularisations, corrections et ajustements d\'inventaire.',
            ],
            'orders_menu_restaurants' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Liste des Commandes Restaurant',
                'description' => 'Gère les enregistrements et le suivi des commandes du restaurant.',
            ],
            'payments' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Listes des Encaissements',
                'description' => 'Enregistre et centralise l’ensemble des règlements d’encaissement.',
            ],
            'expense_payments' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Listes des Dépenses',
                'description' => 'Enregistre et gère les règlements et paiements liés aux dépenses.',
            ],

            // Tables annexes / techniques de vente et stock rattachées
            'nature_warehouse' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Nature des Entrepôts',
                'description' => 'Gère les types d’entrepôts.',
            ],
            'purchase_orders_decisional_notifications' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Notifications Décisionnelles - Commandes d\'Achat',
                'description' => 'Gère les notifications décisionnelles.',
            ],
            'purchase_orders_notifications' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Notifications - Commandes d\'Achat',
                'description' => 'Gère les notifications relatives aux commandes d\'achat.',
            ],
            'purchase_order_items' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Lignes de Commandes d\'Achat',
                'description' => 'Gère les articles des commandes d\'achat.',
            ],
            'supply_invoices' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Factures d\'Approvisionnement',
                'description' => 'Gère les factures liées aux approvisionnements.',
            ],
            'supply_items' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Lignes d\'Approvisionnement',
                'description' => 'Gère les articles composant chaque approvisionnement.',
            ],
            'stocks_deductions_items' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Lignes de Déductions de Stocks',
                'description' => 'Gère les articles composant chaque déduction.',
            ],
            'stock_adjustments_items' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Lignes de Régularisations de Stocks',
                'description' => 'Gère les articles composant chaque régularisation.',
            ],
            'passation_items' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Lignes de Passations de Stocks',
                'description' => 'Gère les articles composant chaque passation.',
            ],
            'passation_managers' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Gestionnaires de Passations',
                'description' => 'Gère les responsables des passations.',
            ],
            'warehouse_managers' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Gestionnaires d\'Entrepôts',
                'description' => 'Gère les gestionnaires des entrepôts.',
            ],
            'stock_snapshots' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Instantanés de Stock',
                'description' => 'Gère l\'historique des états de stock.',
            ],
            'complement_virtual_temps' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Réservation Virtuelle de Compléments',
                'description' => 'Gère la réservation temporaire des compléments.',
            ],
            'complement_virtual_temps_backup' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Sauvegarde des Réservations de Compléments',
                'description' => 'Gère l’historique des réservations de compléments.',
            ],
            'drinks_virtuals_temp' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Réservation Virtuelle de Boissons',
                'description' => 'Gère la réservation temporaire des boissons.',
            ],
            'last_status_items_menus_restaurants' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Dernier Statut des Menus (Restaurants)',
                'description' => 'Gère l\'état du dernier statut de chaque élément de menu.',
            ],
            'last_status_drinks_menus_restaurants' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Dernier Statut des Boissons (Restaurants)',
                'description' => 'Gère l\'état du dernier statut des boissons.',
            ],
            'client_allocations' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Allocation des Arrhes (Clients)',
                'description' => 'Gère le suivi des arrhes et acomptes.',
            ],
            'menu_restaurant_complements' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Liaison des Compléments aux Menus',
                'description' => 'Gère l’association des compléments aux menus.',
            ],
            'menu_virtuals_temp' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Réservation Virtuelle de Menus',
                'description' => 'Gère la réservation temporaire des menus.',
            ],
            'notifications_for_decisionals' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Notifications Décisionnelles (Ventes)',
                'description' => 'Gère les alertes aux décideurs.',
            ],
            'orders_items_complements' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Compléments des Articles de Commandes',
                'description' => 'Gère les compléments des articles commandés.',
            ],
            'orders_menus_status_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Statuts des Boissons de Commandes',
                'description' => 'Gère les statuts des boissons.',
            ],
            'orders_menu_restaurant_items' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Éléments des Commandes de Menus',
                'description' => 'Gère les menus de chaque commande.',
            ],
            'order_menu_item_statuses' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Statuts des Menus de Commandes',
                'description' => 'Gère les statuts de chaque menu.',
            ],
            'order_menu_restaurant_defective_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Boissons Défectueuses des Commandes',
                'description' => 'Gère les boissons déclarées défectueuses.',
            ],
            'order_menu_restaurant_defective_items' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Menus Défectueux des Commandes',
                'description' => 'Gère les menus déclarés défectueux.',
            ],
            'order_notifications' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Notifications des Commandes',
                'description' => 'Gère les notifications pour les postes opérationnels.',
            ],
            'order_restaurannts_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Boissons des Commandes',
                'description' => 'Gère les boissons associées aux commandes.',
            ],
            'virtual_orders_menu_restaurants' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Commandes Virtuelles & Menus',
                'description' => 'Gère les menus et commandes virtuelles.',
            ],
            'statistics_orders_status_menus_restaurants' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Statistiques Commandes & Menus',
                'description' => 'Gère les statistiques des menus.',
            ],
            'statistics_orders_status_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Statistiques Commandes & Boissons',
                'description' => 'Gère les statistiques des boissons.',
            ],
            'refund_histories' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Historique des Remboursements (Arrhes)',
                'description' => 'Gère l\'historique des remboursements.',
            ],
            'payment_lines' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Lignes d’Encaissement',
                'description' => 'Détaille les lignes des paiements.',
            ],
            'payment_regulations' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Règlements de Paiement',
                'description' => 'Gère les modalités des règlements.',
            ],
            'other_cash_ins' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Autres Encaissements',
                'description' => 'Gère les flux financiers entrants divers.',
            ],
        ];

        $tables = DB::select('SHOW TABLES');
        $propertyKey = "Tables_in_{$databaseName}";

        foreach ($tables as $tableObj) {
            $tableName = $tableObj->$propertyKey;
            if (in_array($tableName, ['database_tables_metadata', 'migrations', 'failed_jobs', 'password_reset_tokens'])) {
                continue;
            }

            if (isset($manualDefinitions[$tableName])) {
                $category = $manualDefinitions[$tableName]['category']->value;
                $displayName = $manualDefinitions[$tableName]['display_name'];
                $description = $manualDefinitions[$tableName]['description'];
            } else {
                $category = TableCategoryEnum::UNCATEGORIZED->value;
                $displayName = Str::title(str_replace('_', ' ', $tableName));
                $description = 'Description à définir...';
            }

            DB::table('database_tables_metadata')->updateOrInsert(
                ['table_name' => $tableName],
                [
                    'uuid' => (string) Str::uuid(),
                    'category' => $category,
                    'display_name' => $displayName,
                    'description' => $description,
                    'created_by' => $firstUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}

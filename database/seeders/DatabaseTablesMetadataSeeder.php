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
            'roles' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Liste des Rôles Créés.',
                'description' => 'Profils d’accès, rôles et niveaux de responsabilité.',
            ],
            'users' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Liste des Comptes Utilisateurs.',
                'description' => 'Informations d’identification et profils des utilisateurs.',
            ],
            'settings_restaurants' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Paramètres de Facturation & Déconnexion',
                'description' => 'Gère les paramètres généraux de facturation (dont logout_period).',
            ],

            // ***************** Libellées / variables Réutilisables ***************************
            'suppliers' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Fournisseurs',
                'description' => 'Liste des fournisseurs.',
            ],
            'warehouses' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Entrepôts',
                'description' => 'Liste des entrepôts.',
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
                'display_name' => 'Liste des Boissons du Bar',
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
                'display_name' => 'Liste des clients partenaires',
                'description' => 'Gère les clients partenaires du restaurant.',
            ],
            'free_clients_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Clients Gratuits',
                'description' => 'Gère les clients bénéficiant de gratuités dans le restaurant.',
            ],
            'room_services' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Tarifaire du Room Service',
                'description' => 'Gère la tarification du room service.',
            ],
            'sales_categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Liste des Rubriques de Vente',
                'description' => 'Gère les rubriques de facturation des ventes.',
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
                'display_name' => 'Liste des Sous Catégories de Dépenses.',
                'description' => 'Gère les familles et sous-catégories de dépenses.',
            ],

            // ***************** Données utilisées ***************************
            'produit_point' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Quantité d\'articles dans tous les stocks.',
                'description' => 'Gère les stocks et la disponibilité des produits pour chaque entrepôt ou point de vente.',
            ],
            'purchase_orders' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Commandes d\'Articles.',
                'description' => 'Gère les commandes d\'achat pour la gestion des stocks.',
            ],
            'supplies' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Approvisionnements.',
                'description' => 'Gère les approvisionnements et les entrées de stock.',
            ],
            'stocks_deductions' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Déductions de Stocks.',
                'description' => 'Gère les déductions et sorties de stocks.',
            ],
            'passations' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Passations de Stocks.',
                'description' => 'Gère les passations et transferts de stocks entre entités.',
            ],
            'stock_adjustments' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Régularisations de Stocks.',
                'description' => 'Gère les régularisations, corrections et ajustements d\'inventaire.',
            ],
            'orders_menu_restaurants' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Liste des Commandes Restaurant.',
                'description' => 'Gère les enregistrements et le suivi des commandes du restaurant.',
            ],
            'payments' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Listes des Encaissements.',
                'description' => 'Enregistre et centralise l’ensemble des règlements d’encaissement.',
            ],
            'expense_payments' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Listes des Dépenses.',
                'description' => 'Enregistre et gère les règlements et paiements liés aux dépenses.',
            ],
            'stock_performance_criteria' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Listes des Critères d’écoulement de stocks',
                'description' => 'Enregistre et gère les critères d’écoulement de stocks.',
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

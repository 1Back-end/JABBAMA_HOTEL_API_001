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
                'display_name' => 'Rôles Utilisateurs',
                'description' => 'Profils d’accès et niveaux de responsabilité.',
            ],
            'permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Permissions du Système',
                'description' => 'Droits et actions granulaires de l’application.',
            ],
            'users' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Comptes Utilisateurs',
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
                'display_name' => 'Jetons d’Accès (API & Sessions)',
                'description' => 'Jetons d’authentification sécurisés (Sanctum).',
            ],
            'units' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Unités de Mesure',
                'description' => 'Unités de mesure des articles (kg, litre, pièce, etc.).',
            ],
            'categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Catégories d’Articles',
                'description' => 'Classification des articles par famille.',
            ],
            'nature_entrepots' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Natures d’Entrepôts',
                'description' => 'Types et natures des zones de stockage.',
            ],
            'category_tree' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Sous-catégories d’Articles',
                'description' => 'Arborescence pour affiner le classement des articles.',
            ],
            'cash_receipt_types' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Catégories d’Encaissements',
                'description' => 'Gère les différents catégories d’encaissement.',
            ],
            'cash_receipt_families' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Familles d’Encaissement',
                'description' => 'Gère les sous-catégories d’encaissement.',
            ],
            'restaurant_expense_types' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Types de Dépenses',
                'description' => 'Gère les différentes catégories de dépenses.',
            ],
            'restaurant_expense_types_families' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Familles de Dépenses',
                'description' => 'Gère les sous-catégories de dépenses.',
            ],
            'room_services' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Tarification Room Service',
                'description' => 'Gère la tarification du room service.',
            ],
            'sales_categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Rubriques de Facturation',
                'description' => 'Gère les rubriques de facturation des ventes.',
            ],
            'settings_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Paramètres de Facturation',
                'description' => 'Gère les paramètres généraux de facturation du restaurant.',
            ],
            'menu_categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Catégories de Menu',
                'description' => 'Gère le classement des catégories de menus.',
            ],
            'menus_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Menus du Restaurant',
                'description' => 'Gère les menus du restaurant.',
            ],
            'configurations_complements' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Compléments et Boissons Chaudes',
                'description' => 'Gère les compléments et des boissons chaudes.',
            ],
            'restaurant_drink_configurations' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Configurations des Boissons',
                'description' => 'Gère les configurations des boissons du bar.',
            ],
            'regulation_methods' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Modes de Règlement',
                'description' => 'Gère les différents modes de règlement disponibles.',
            ],
            'restaurant_tables' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Tables du Restaurant',
                'description' => 'Gère les tables du restaurant.',
            ],
            'room_service' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Service des Étage',
                'description' => 'Gère la configuration du service des étages.',
            ],
            'restaurant_rooms' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Service de Chambres',
                'description' => 'Gère la configuration des chambres.',
            ],
            'restaurant_partners' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Clients Partenaires',
                'description' => 'Gère les clients partenaires du restaurant.',
            ],
            'complements_compositions' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Compositions des Compléments',
                'description' => 'Gère la confection des compléments et boissons.',
            ],
            'complements_compositions_items' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Éléments des Compositions de Compléments',
                'description' => 'Gère les articles inclus dans la confection des compléments.',
            ],
            'menu_orders' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Commandes de Menus',
                'description' => 'Gère la confection de menus.',
            ],
            'menu_orders_items' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Éléments des Commandes de Menus',
                'description' => 'Gère les articles inclus dans la confection des menus.',
            ],
            'free_clients_restaurants' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Clients Gratuits',
                'description' => 'Gère les clients bénéficiant de gratuités dans le restaurant.',
            ],
            'drink_compositions' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Confections de Boissons',
                'description' => 'Gère la confection des boissons du bar.',
            ],
            'drink_composition_items' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Éléments de Confection de Boissons',
                'description' => 'Gère les articles inclus dans la confection de boissons.',
            ],
            'complement_virtual_temps' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Réservation Virtuelle de Compléments',
                'description' => 'Gère la réservation temporaire des compléments aux commandes du restaurant.',
            ],
            'complement_virtual_temps_backup' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Sauvegarde des Réservations Virtuelles de Compléments',
                'description' => 'Gère l’historique des réservations temporaires de compléments liées aux commandes.',
            ],
            'drinks_virtuals_temp' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Réservation Virtuelle de Boissons',
                'description' => 'Gère la réservation temporaire des boissons aux commandes du restaurant.',
            ],
            'menu_restaurant_complements' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Liaison des Compléments aux Menus',
                'description' => 'Gère l’association des compléments aux menus du restaurant.',
            ],
            'menu_virtuals_temp' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Réservation Virtuelle de Menus',
                'description' => 'Gère la réservation temporaire des menus lors des commandes du restaurant.',
            ],
            'notifications_for_decisionals' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Notifications Décisionnelles',
                'description' => 'Gère les notifications et alertes aux décideurs du restaurant.',
            ],
            'orders_items_complements' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Compléments des Articles de Commandes',
                'description' => 'Gère les compléments associés aux articles composant les commandes du restaurant.',
            ],
            'orders_menus_status_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Statuts des Boissons de Commandes',
                'description' => 'Gère les statuts des différentes boissons du restaurant.',
            ],
            'orders_menu_restaurants' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Commandes de Menus du Restaurant',
                'description' => 'Gère les enregistrements et le suivi des commandes du restaurant.',
            ],
            'orders_menu_restaurant_items' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Éléments des Commandes de Menus',
                'description' => 'Gère les menus de chaque commande du restaurant.',
            ],
            'order_menu_item_statuses' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Statuts des Menus de Commandes',
                'description' => 'Gère les statuts de chaque menu au sein des commandes.',
            ],
            'order_menu_restaurant_defective_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Boissons Défectueuses des Commandes',
                'description' => 'Gère les boissons qui ont été déclarées défectueuses lors des commandes.',
            ],
            'order_menu_restaurant_defective_items' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Menus Défectueux des Commandes',
                'description' => 'Gère les menus qui ont été déclarés défectueux lors des commandes.',
            ],
            'order_notifications' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Notifications des Commandes',
                'description' => 'Gère les notifications des commandes pour les postes opérationnels.',
            ],
            'order_restaurannts_drinks' => [
                'category' => TableCategoryEnum::SALES,
                'display_name' => 'Boissons des Commandes',
                'description' => 'Gère les boissons associées à chaque commande du restaurant.',
            ],
            'purchase_orders' => [
                'category' => TableCategoryEnum::STOCK,
                'display_name' => 'Commandes d\'Achat',
                'description' => 'Gère les commandes d\'achat pour la gestion des stocks et des approvisionnements.',
            ],
            'payments' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Encaissements',
                'description' => 'Enregistre et centralise l’ensemble des transactions et règlements d’encaissement.',
            ],
            'payment_lines' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Lignes d’Encaissement',
                'description' => 'Détaille les lignes et articles rattachés à chaque transaction de paiement.',
            ],
            'payment_regulations' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Règlements de Paiement',
                'description' => 'Gère les modes et modalités des règlements de paiement.',
            ],
            'expense_payments' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Paiements de Dépenses',
                'description' => 'Enregistre et gère les règlements et paiements liés aux dépenses.',
            ],
            'other_cash_ins' => [
                'category' => TableCategoryEnum::FINANCE,
                'display_name' => 'Autres Encaissements',
                'description' => 'Gère les encaissements divers et autres flux financiers entrants.',
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

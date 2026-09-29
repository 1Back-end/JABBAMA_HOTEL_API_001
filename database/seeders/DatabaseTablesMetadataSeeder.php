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
                'description' => 'Définit les profils d’accès et les niveaux de responsabilité (Administrateur, Gérant, etc.).',
            ],
            'permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Permissions du Système',
                'description' => 'Répertorie l’ensemble des droits et actions granulaires réalisables dans l’application.',
            ],
            'users' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Comptes Utilisateurs',
                'description' => 'Centralise les informations d’identification, les mots de passe et les profils des utilisateurs.',
            ],
            'modules_applications' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Modules Applicatifs',
                'description' => 'Gère l’activation, la désactivation et la configuration globale des différents modules de l’application.',
            ],
            'role_has_permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Attribution Rôles-Permissions',
                'description' => 'Table de liaison croisant les rôles de sécurité avec leurs permissions associées.',
            ],
            'categories_permissions' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Catégories de Permissions',
                'description' => 'Regroupe logiquement les permissions par grand domaine fonctionnel ou par module.',
            ],
            'model_has_roles' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Attribution Modèles-Rôles',
                'description' => 'Table de liaison affectant un ou plusieurs rôles spécifiques aux comptes utilisateurs.',
            ],
            'module_permission' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Association Modules-Permissions',
                'description' => 'Liaison structurelle rattachant les permissions aux modules applicatifs correspondants.',
            ],
            'personal_access_tokens' => [
                'category' => TableCategoryEnum::SYSTEM,
                'display_name' => 'Jetons d’Accès (API & Sessions)',
                'description' => 'Stocke les jetons d’authentification sécurisés (Laravel Sanctum) pour les connexions et appels API.',
            ],
            'units' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Unités de Mesure',
                'description' => 'Gère les unités de mesure des articles et des produits (kg, litre, pièce, portion, etc.).',
            ],
            'categories' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Catégories d’Articles',
                'description' => 'Permet de classifier et organiser les articles ou produits par famille (ex: Boissons, Plats, Ingrédients).',
            ],
            'nature_entrepots' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Natures d’Entrepôts',
                'description' => 'Définit les différents types ou natures d’entrepôts et de zones de stockage (ex: dépôt principal, cuisine, réserve, etc.).',
            ],
            'category_tree' => [
                'category' => TableCategoryEnum::CONFIGURATION,
                'display_name' => 'Sous-catégories d’Articles',
                'description' => 'Gère l’arborescence et les sous-catégories pour affiner le classement des articles.',
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

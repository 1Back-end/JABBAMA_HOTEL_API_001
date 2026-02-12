<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class ExtractPermissions extends Command
{
    protected $signature = 'permissions:extract';
    protected $description = 'Synchronise les permissions avec les annotations des contrôleurs et les catégorise par menu.';

    // 🔹 Permissions manuelles
    protected array $manualPermissions = [
        'view_all_products' => [
            'description' => 'Accéder à tous les produits, indépendamment de son rôle.',
            'category' => 'Permissions supplémentaires',
        ],
        'view_all_warehouses' => [
            'description' => 'Accéder à tous les entrepôts, indépendamment de son rôle.',
            'category' => 'Permissions supplémentaires',
        ],
        'view_all_passations' => [
            'description' => "Accéder à toutes les passations de stocks, indépendamment de son rôle.",
            'category' => 'Permissions supplémentaires',
        ],
        'view_role_related_data' => [
            'description' => 'Partager les mêmes informations entre utilisateurs ayant le même rôle.',
            'category' => 'Permissions supplémentaires',
        ],
        'view_transferred_orders' => [
            'description' => 'Permettre aux gestionnaires de stock de voir toutes les commandes qui leur ont été transférées.',
            'category' => 'Gestion des commandes',
        ],
        'view_transferred_supplies' => [
            'description' => 'Permettre aux gestionnaires de stock de voir tous les approvisionnements qui leur ont été transférées.',
            'category' => 'Gestion des approvisionnements',
        ],
        'view_all_products_access' => [
            'description' => 'Permettre aux gestionnaires de stock de voir tous les articles du système au meme dégré que le SUPER ADMIN.',
            'category' => 'Gestion des articles',
        ],
        'view_all_menus_restaurants' => [
            'description' => 'Accéder à tous les menus du restaurant, indépendamment de son rôle.',
            'category' => 'Gestion des menus du restaurant',
        ],
        'view_all_restaurants_tables' => [
            'description' => 'Accéder à toutes les tables du restaurant, indépendamment de son rôle.',
            'category' => 'Gestion des tables du restaurant',
        ],
        'view_all_category_menus' => [
            'description' => 'Accéder à toutes les catégories de menus du restaurant, indépendamment de son rôle.',
            'category' => 'Gestion des catégories de menus',
        ],
        'view_access_setting' => [
            'description' => 'Accéder aux paramètres d\'accès du système',
            'category' => 'Permissions supplémentaires',
        ],
        'view_access_setting_stocks' => [
            'description' => 'Accéder aux paramètres de stocks du système',
            'category' => 'Permissions supplémentaires',
        ],
        'view_access_setting_restaurants' => [
            'description' => 'Accéder aux paramètres du restaurant du système',
            'category' => 'Permissions supplémentaires',
        ],
        'view_access_setting_others_transferts' => [
            'description' => 'Accéder aux paramètres de transferts de stocks du système',
            'category' => 'Permissions supplémentaires',
        ],
        'view_all_restaurant_partners' => [
            'description' => 'Accéder à tous les partenaires du restaurant du système',
            'category' => 'Gestion des partenaires',
        ],
        'view_all_menu_orders' => [
            'description' => 'Accéder à toutes les compositions des menus du restaurant',
            'category' => 'Composition des menus du restaurant',
        ]
    ];

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];

        $systemUser = User::where('login', 'SYSTEM')->first();
        $systemId = $systemUser?->id ?? 1;

        $superAdminRole = Role::find(1);

        // -----------------------
        // 1️⃣ Extraction des permissions depuis les contrôleurs
        // -----------------------
        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("\n--- Synchronisation des permissions ---");

        $controllerPermissionNames = collect($permissions)
            ->flatMap(fn ($methods) => collect($methods)->pluck('permission'))
            ->filter()
            ->values()
            ->toArray();

        // -----------------------
        // 2️⃣ Création / mise à jour des permissions issues des contrôleurs
        // -----------------------
        foreach ($permissions as $controller => $methods) {

            $categoryName = $this->extractControllerCategory($controller) ?? 'Autres';

            $category = PermissionCategory::firstOrCreate(
                ['libelle' => $categoryName],
                [
                    'description' => $categoryName,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            foreach ($methods as $perm) {
                if (empty($perm['permission'])) continue;

                $permission = Permission::updateOrCreate(
                    ['name' => $perm['permission']],
                    [
                        'description' => $perm['permission_desc'] ?? '',
                        'category_id' => $category->id,
                        'system' => true,
                        'active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                $this->info(
                    $permission->wasRecentlyCreated
                        ? "✅ Créée : {$permission->name}"
                        : "🔁 Mise à jour : {$permission->name}"
                );

                if ($superAdminRole && !$superAdminRole->permissions->contains($permission->id)) {
                    $superAdminRole->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }
            }
        }

        // -----------------------
        // 3️⃣ Création / mise à jour des permissions manuelles
        // -----------------------
        foreach ($this->manualPermissions as $name => $data) {

            $category = PermissionCategory::firstOrCreate(
                ['libelle' => $data['category']],
                [
                    'description' => $data['category'],
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'description' => $data['description'],
                    'category_id' => $category->id,
                    'system' => true,
                    'active' => true,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $this->info(
                $permission->wasRecentlyCreated
                    ? "✅ Créée : {$name}"
                    : "🔁 Mise à jour : {$name}"
            );

            if ($superAdminRole && !$superAdminRole->permissions->contains($permission->id)) {
                $superAdminRole->permissions()->attach($permission->id, [
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]);
            }
        }

        // -----------------------
        // 4️⃣ Suppression des permissions système obsolètes
        // -----------------------
        $validPermissions = array_merge(
            $controllerPermissionNames,
            array_keys($this->manualPermissions)
        );

        $obsoletePermissions = Permission::where('system', true)
            ->whereNotIn('name', $validPermissions)
            ->get();

        foreach ($obsoletePermissions as $permission) {
            $permission->delete();
            $this->warn("🗑️ Permission supprimée : {$permission->name}");
        }

        // -----------------------
        // 5️⃣ Suppression des catégories vides
        // -----------------------
        $usedCategoryIds = Permission::pluck('category_id')->unique()->filter();

        PermissionCategory::whereNotIn('id', $usedCategoryIds)
            ->each(function ($category) {
                $category->delete();
                $this->warn("🗑️ Catégorie supprimée : {$category->libelle}");
            });

        $this->info("\n✅ Synchronisation terminée avec succès !");
    }


    private function getControllers($directory, $namespace = 'App\\Http\\Controllers'): array
    {
        $controllers = [];
        foreach (scandir($directory) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                $controllers = array_merge($controllers, $this->getControllers($fullPath, $namespace . '\\' . $file));
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $controllers[] = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);
            }
        }
        return $controllers;
    }

    private function extractPermissionsFromController($controller, &$permissions): void
    {
        if (!class_exists($controller)) return;

        $reflection = new ReflectionClass($controller);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (!$docComment) continue;

            $permission = $this->extractTagValue($docComment, '@permission');
            $permissionDesc = $this->extractTagValue($docComment, '@permission_desc');

            if ($permission) {
                $permissions[$controller][$method->getName()] = [
                    'permission' => $permission,
                    'permission_desc' => $permissionDesc ?? ''
                ];
            }
        }
    }

    private function extractControllerCategory($controller): ?string
    {
        $reflection = new ReflectionClass($controller);
        $docComment = $reflection->getDocComment();
        return $docComment ? $this->extractTagValue($docComment, '@permission_category') : null;
    }

    private function extractTagValue($doc, $tag): ?string
    {
        return preg_match('/' . preg_quote($tag) . '\s+(.+)/', $doc, $matches)
            ? trim($matches[1])
            : null;
    }
}

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
    protected string $defaultManualModule = 'Autres Modules';


    // 🔹 Permissions manuelles
    protected array $manualPermissions = [
        'view_all_products' => [
            'description' => 'Accéder à tous les produits, indépendamment de son rôle.',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_all_warehouses' => [
            'description' => 'Accéder à tous les entrepôts, indépendamment de son rôle.',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_all_passations' => [
            'description' => "Accéder à toutes les passations de stocks, indépendamment de son rôle.",
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks','Autres Modules'],
        ],
        'view_role_related_data' => [
            'description' => 'Partager les mêmes informations entre utilisateurs ayant le même rôle.',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_transferred_orders' => [
            'description' => 'Permettre aux gestionnaires de stock de voir toutes les commandes qui leur ont été transférées.',
            'category' => 'Gestion des commandes',
            'modules' => ['Gestion des stocks','Autres Modules'],
        ],
        'view_transferred_orders_for_restaurant' => [
            'description' => 'Permettre aux cuisiniers  de voir toutes les commandes du restaurant qui leur ont été transférées.',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_transferred_supplies' => [
            'description' => 'Permettre aux gestionnaires de stock de voir tous les approvisionnements qui leur ont été transférées.',
            'category' => 'Gestion des approvisionnements',
            'modules' => ['Gestion des stocks','Autres Modules'],
        ],
        'view_all_products_access' => [
            'description' => 'Permettre aux gestionnaires de stock de voir tous les articles du système au meme dégré que le SUPER ADMIN.',
            'category' => 'Gestion des articles',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_all_menus_restaurants' => [
            'description' => 'Accéder à tous les menus du restaurant, indépendamment de son rôle.',
            'category' => 'Gestion des menus du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_restaurants_tables' => [
            'description' => 'Accéder à toutes les tables du restaurant, indépendamment de son rôle.',
            'category' => 'Gestion des tables du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_category_menus' => [
            'description' => 'Accéder à toutes les catégories de menus du restaurant, indépendamment de son rôle.',
            'category' => 'Gestion des catégories de menus',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_access_setting' => [
            'description' => 'Accéder aux paramètres d\'accès du système',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_access_setting_stocks' => [
            'description' => 'Accéder aux paramètres de stocks du système',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks','Autres Modules','Gestion du restaurant'],
        ],
        'view_access_setting_restaurants' => [
            'description' => 'Accéder aux paramètres du restaurant du système',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_access_setting_others_transferts' => [
            'description' => 'Accéder aux paramètres de transferts de stocks du système',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion des stocks','Autres Modules'],
        ],
        'view_all_restaurant_partners' => [
            'description' => 'Accéder à tous les partenaires du restaurant du système',
            'category' => 'Gestion des partenaires',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_menu_orders' => [
            'description' => 'Accéder à toutes les compositions des menus du restaurant',
            'category' => 'Composition des menus du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_restaurant_rooms' => [
            'description' => 'Accéder à toutes les chambres du restaurant',
            'category' => 'Gestion des chambres',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_orders_module_applications' => [
            'description' => 'Accéder à tous les modules de l\'application',
            'category' => 'Gestion des modules de l\'application',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_access_for_dashboard' => [
            'description' => 'Accéder au dashboard',
            'category' => 'Gestion des statistiques',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_access_for_modules_applications' => [
            'description' => 'Accéder aux modules de l\'application',
            'category' => 'Gestion des modules de l\'application',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Autres Modules'],
        ],
        'view_access_for_floor_room_services' => [
            'description' => 'Accéder aux services des étages',
            'category' => 'Gestion du service des étages',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_access_for_applied_remise' => [
            'description' => 'Appliquer une remise lors de la prise d\'une commande' ,
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_access_for_date_orders' => [
            'description' => 'Accéder à la date lors de la prise d\'une commande' ,
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'definied_warehouses_is_used_for_restaurant' => [
            'description' => 'Définir l\'entrepôt cuisine utilisée pour le restaurant' ,
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion du restaurant','Autres Modules','Gestion des stocks'],
        ],
        'definied_warehouses_is_other_transformation' => [
            'description' => 'Définir l\'entrepôt de transformation des boissons pour le restaurant' ,
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion du restaurant','Autres Modules','Gestion des stocks'],
        ],
        'definied_warehouses_is_bar_warehouse' => [
            'description' => 'Définir l\'entrepôt bar utilisé pour le restaurant' ,
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion du restaurant','Autres Modules','Gestion des stocks'],
        ],
        'see_menus_for_order_restaurant' => [
            'description' => 'Afficher les plats d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'see_drinks_for_order_restaurant' => [
            'description' => 'Afficher les boissons d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],

        'update_items_for_facture' => [
            'description' => 'Afficher le bouton d\'ajustement d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'update_order_for_delivered' => [
            'description' => 'Afficher le bouton de mise en servie  d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_transferred' => [
            'description' => 'Afficher les notifications d\'une commande transférée',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_preparation' => [
            'description' => 'Afficher les notifications d\'une commande en cours de préparation',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_rejected' => [
            'description' => 'Afficher les notifications d\'une commande en rejetée',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_defective' => [
            'description' => 'Afficher les notifications d\'une commande en défectieux',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_ready' => [
            'description' => 'Afficher les notifications d\'une commande en prêt',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_delivered' => [
            'description' => 'Afficher les notifications d\'une commande en servie',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_rejected_after_validation' => [
            'description' => 'Afficher les notifications d\'une commande en rejet du servi',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_cancel_for_new_update' => [
            'description' => 'Afficher les notifications d\'une commande en rejet du prêt',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_partial_delivered' => [
            'description' => 'Afficher les notifications d\'une commande en servie partiellement',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification_in_partial_completed' => [
            'description' => 'Afficher les notifications d\'une commande en prêt partiellement',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_all_notification' => [
            'description' => 'Afficher la liste des notifications d\'une commande',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_kitchen_orders' => [
            'description' => 'Afficher les commandes contenant des menus',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Autres Modules'],
        ],
        'view_bar_orders' => [
            'description' => 'Afficher les commandes contenant des boissons',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Autres Modules'],
        ],
        'view_kitchen_and_bar_orders' => [
            'description' => 'Afficher les commandes contenant des menus et/ou des boissons (cuisine + bar)',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Autres Modules'],
        ],
        'view_unit_price_and_total_price' => [
            'description' => 'Afficher le prix unitaire, le prix total des items et le montant total d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Autres Modules'],
        ],
        'view_kitchen_notifications' => [
            'description' => 'Afficher les notifications des commandes contenant des menus/plats',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
        'view_bar_notifications' => [
            'description' => 'Afficher les notifications des commandes contenant des boissons',
            'category' => 'Gestion des notifications du restaurant',
            'modules' => ['Gestion du restaurant','Autres Modules'],
        ],
    ];

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];

        $systemUser = User::where('login', 'SYSTEM')->first();
        $systemId = $systemUser?->id ?? 1;
        $superAdminRole = Role::find(1);

        // 1️⃣ Extraction des permissions depuis les contrôleurs
        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("\n--- Synchronisation des permissions et modules ---");

        $validPermissions = [];

        // ----------------------- Permissions des contrôleurs -----------------------
        foreach ($permissions as $controller => $methods) {
            ksort($methods);
            usort($methods, fn($a, $b) => strcmp($a['permission'], $b['permission']));
            foreach ($methods as $perm) {
                $categoryName = $perm['category'] ?? 'Autres';
                $modules = $perm['modules'] ?? [$this->defaultManualModule];

                // ✅ Catégorie
                $category = PermissionCategory::firstOrCreate(
                    ['libelle' => $categoryName],
                    [
                        'description' => $categoryName,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                // ✅ Permission
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

                $validPermissions[] = $permission->name;

                // ✅ Attachement à tous les modules
                foreach ($modules as $moduleName) {
                    $moduleSlug = \Str::slug($moduleName);

                    $module = \App\Models\ModuleApplications::firstOrCreate(
                        ['slug' => $moduleSlug],
                        [
                            'name' => $moduleName,
                            'description' => $moduleName,
                            'is_active' => true,
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]
                    );

                    $this->info("✅ Module synchronisé : {$module->name}");

                    if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                        $module->permissions()->attach($permission->id, [
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]);
                    }

                    // ✅ Optionnel : remplir module_uuid si tu veux le lien direct
                    $permission->module_uuid = $module->uuid;
                    $permission->save();
                }

                // ✅ Attachement au super admin
                if ($superAdminRole && !$superAdminRole->permissions()->where('permission_id', $permission->id)->exists()) {
                    $superAdminRole->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }

                $this->info("✅ Permission synchronisée : {$permission->name}");
            }
        }

        // ----------------------- Permissions manuelles -----------------------
        $manualPermissions = $this->manualPermissions;
        uksort($manualPermissions, fn($a, $b) => strcmp($a['description'] ?? '', $b['description'] ?? ''));
        foreach ($this->manualPermissions as $name => $data) {
            $categoryName = $data['category'] ?? 'Autres';
            $modules = $data['modules'] ?? [$this->defaultManualModule];

            $category = PermissionCategory::firstOrCreate(
                ['libelle' => $categoryName],
                [
                    'description' => $categoryName,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'description' => $data['description'] ?? '',
                    'category_id' => $category->id,
                    'system' => true,
                    'active' => true,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $validPermissions[] = $permission->name;

            foreach ($modules as $moduleName) {
                $moduleSlug = \Str::slug($moduleName);

                $module = \App\Models\ModuleApplications::firstOrCreate(
                    ['slug' => $moduleSlug],
                    [
                        'name' => $moduleName,
                        'description' => $moduleName,
                        'is_active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                $this->info("✅ Module synchronisé : {$module->name}");

                if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                    $module->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }

                // Optionnel : module_uuid
                $permission->module_uuid = $module->uuid;
                $permission->save();
            }

            if ($superAdminRole && !$superAdminRole->permissions()->where('permission_id', $permission->id)->exists()) {
                $superAdminRole->permissions()->attach($permission->id, [
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]);
            }

            $this->info("✅ Permission manuelle synchronisée : {$permission->name}");
        }

        // ----------------------- Nettoyage -----------------------
        Permission::where('system', true)
            ->whereNotIn('name', $validPermissions)
            ->get()
            ->each(function ($permission) {
                $permission->delete();
                $this->warn("🗑️ Permission supprimée : {$permission->name}");
            });

        $usedCategoryIds = Permission::pluck('category_id')->unique()->filter();
        PermissionCategory::whereNotIn('id', $usedCategoryIds)->each(function ($category) {
            $category->delete();
            $this->warn("🗑️ Catégorie supprimée : {$category->libelle}");
        });

        $usedModuleIds = \DB::table('module_permission')->pluck('module_uuid')->unique()->filter();
        \App\Models\ModuleApplications::whereNotIn('uuid', $usedModuleIds)
            ->get()
            ->each(function ($module) {
                $module->delete();
                $this->warn("🗑️ Module supprimé : {$module->name}");
            });

        $this->info("\n✅ Synchronisation complète terminée !");
    }

    /**
     * Extraction des permissions depuis un contrôleur avec catégorie et modules multiples
     */
    private function extractPermissionsFromController(string $controller, array &$permissions): void
    {
        if (!class_exists($controller)) return;

        $reflection = new \ReflectionClass($controller);

        // Docblock du contrôleur
        $doc = $reflection->getDocComment() ?: '';

        // Récupère la catégorie (une seule)
        $category = $this->extractTagValue($doc, '@permission_category') ?: 'Autres';

        // Récupère tous les modules (peut être plusieurs)
        $modules = $this->extractTagValues($doc, '@permission_module');
        if (empty($modules)) {
            $modules = ['Autres Modules']; // fallback
        }

        // Parcours des méthodes publiques
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (!$docComment) continue;

            $permission     = $this->extractTagValue($docComment, '@permission');
            $permissionDesc = $this->extractTagValue($docComment, '@permission_desc') ?? '';

            if ($permission) {
                $permissions[$controller][$method->getName()] = [
                    'permission'      => $permission,
                    'permission_desc' => $permissionDesc,
                    'category'        => $category,
                    'modules'         => $modules, // tableau de modules
                ];
            }
        }
    }

    /**
     * Récupère toutes les valeurs d'un tag dans un docblock
     * Permet de gérer plusieurs modules
     */
    private function extractTagValues(string $doc, string $tag): array
    {
        preg_match_all('/' . preg_quote($tag, '/') . '\s+(.+)/', $doc, $matches);
        return isset($matches[1]) ? array_map('trim', $matches[1]) : [];
    }

    /**
     * Récupère tous les contrôleurs d'un répertoire récursivement
     */
    private function getControllers(string $directory, string $namespace = 'App\\Http\\Controllers'): array
    {
        $controllers = [];

        foreach (scandir($directory) as $file) {
            if (in_array($file, ['.', '..'])) continue;

            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                $controllers = array_merge(
                    $controllers,
                    $this->getControllers($fullPath, $namespace . '\\' . $file)
                );
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $controllers[] = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $controllers;
    }

    /**
     * Récupère la catégorie définie dans le contrôleur via @permission_category
     */
    private function extractControllerCategory(string $controller): ?string
    {
        $reflection = new \ReflectionClass($controller);
        return $reflection->getDocComment()
            ? $this->extractTagValue($reflection->getDocComment(), '@permission_category')
            : null;
    }

    /**
     * Extrait une seule valeur d’un tag dans un docblock
     */
    private function extractTagValue(string $doc, string $tag): ?string
    {
        return preg_match('/' . preg_quote($tag, '/') . '\s+(.+)/', $doc, $matches)
            ? trim($matches[1])
            : null;
    }

}

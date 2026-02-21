<?php

namespace App\Http\Controllers;

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\PermissionCategory;
use ReflectionClass;
use ReflectionMethod;

/**
 * @permission_category Gestion de l'extractions des permissions
 * @permission_module Gestion du restaurant
 * @permission_module Gestion des stocks
 */
class PermissionAdminController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission PermissionAdminController::sync_permissions
     * @permission_desc Synchroniser les permissions du systèmes par le SUPER ADMIN
     */
    public function sync_permissions(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ]);

        // Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

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

        // ----------------------- Contrôleurs -----------------------
        foreach ($permissions as $controller => $methods) {
            foreach ($methods as $perm) {
                $categoryName = $perm['category'] ?? 'Autres';
                $modules = $perm['modules'] ?? ['Autres Modules']; // tableau de modules

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

                // ✅ Attache la permission à tous les modules
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

                    $this->info("📦 Module synchronisé : {$module->name}");

                    if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                        $module->permissions()->attach($permission->id, [
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]);
                    }
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
        foreach ($this->manualPermissions as $name => $data) {
            $categoryName = $data['category'] ?? 'Autres';
            $modules = $data['modules'] ?? [$data['module'] ?? 'Autres Modules']; // support multi-modules

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

            // Attachement à tous les modules
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

                $this->info("📦 Module synchronisé : {$module->name}");

                if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                    $module->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }
            }

            // Attachement au super admin
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

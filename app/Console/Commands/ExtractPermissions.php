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
    ];

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];
        $userSYSTEM = User::where('login', 'SYSTEM')->first();
        $systemId = $userSYSTEM?->id ?? 1;
        $role = Role::find(1);

        // -----------------------
        // 1️⃣ Extraction des permissions depuis les contrôleurs
        // -----------------------
        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("\n--- Synchronisation des permissions ---");

        $allControllerPermissions = collect($permissions)
            ->flatMap(fn($methods) => collect($methods)->pluck('permission'))
            ->filter()
            ->toArray();

        // -----------------------
        // 2️⃣ Création / mise à jour des permissions des contrôleurs
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

            foreach ($methods as $method => $perm) {
                if (empty($perm['permission'])) continue;

                $permission = Permission::updateOrCreate(
                    ['name' => $perm['permission']],
                    [
                        'description' => $perm['permission_desc'] ?? '',
                        'category_id' => $category->id,
                        'system' => true,
                        'active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId
                    ]
                );

                $this->info($permission->wasRecentlyCreated ? "✅ Créée : {$permission->name}" : "🔁 Mise à jour : {$permission->name}");

                if ($role && !$role->permissions->contains($permission->id)) {
                    $role->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId
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

            $this->info($permission->wasRecentlyCreated ? "✅ Créée : {$name}" : "🔁 Mise à jour : {$name}");

            if ($role && !$role->permissions->contains($permission->id)) {
                $role->permissions()->attach($permission->id, [
                    'created_by' => $systemId,
                    'updated_by' => $systemId
                ]);
                $this->info("✅ Permission attachée au rôle SUPER_ADMIN");
            }
        }

        // -----------------------
        // 4️⃣ Suppression des permissions obsolètes provenant uniquement des contrôleurs
        // -----------------------
        $toDelete = Permission::whereNotIn('name', $allControllerPermissions)
            ->where('system', true)
            ->whereNotIn('name', array_keys($this->manualPermissions))
            ->get();

        foreach ($toDelete as $perm) {
            $perm->delete();
            $this->warn("🗑️ Supprimée : {$perm->name}");
        }

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

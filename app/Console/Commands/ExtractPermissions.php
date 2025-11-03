<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\Menu;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class ExtractPermissions extends Command
{
    protected $signature = 'permissions:extract';
    protected $description = 'Synchronise les permissions avec les annotations des contrôleurs et les catégorise par menu.';

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];

        $userSYSTEM = User::where('login', 'SYSTEM')->first();
        $role = Role::find(1);

        // Extraire les permissions des contrôleurs
        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("\n--- Synchronisation des permissions ---");

        $allControllerPermissions = collect($permissions)
            ->flatMap(fn($methods) => collect($methods)->pluck('permission'))
            ->filter()
            ->toArray();

        // Création/Mise à jour des permissions
        foreach ($permissions as $controller => $methods) {
            $categoryName = $this->extractControllerCategory($controller) ?? 'Autres';
            $category = PermissionCategory::firstOrCreate(
                ['libelle' => $categoryName],
                [
                    'description' => $categoryName,
                    'created_by' => $userSYSTEM->id,
                    'updated_by' => $userSYSTEM->id,
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
                        'created_by' => $userSYSTEM->id,
                        'updated_by' => $userSYSTEM->id
                    ]
                );

                $this->info($permission->wasRecentlyCreated ? "✅ Créée : {$permission->name}" : "🔁 Mis à jour : {$permission->name}");

                // Attribution au rôle par défaut
                if ($role && !$role->permissions->contains($permission->id)) {
                    $role->permissions()->attach($permission->id, [
                        'created_by' => $userSYSTEM->id,
                        'updated_by' => $userSYSTEM->id
                    ]);
                }
            }
        }

        // Suppression des permissions obsolètes
        $toDelete = Permission::whereNotIn('name', $allControllerPermissions)
            ->where('system', true)
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

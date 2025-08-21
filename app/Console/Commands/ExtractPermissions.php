<?php

namespace App\Console\Commands;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class ExtractPermissions extends Command
{
    protected $signature = 'permissions:extract';
    protected $description = 'Extract @permission and @permission_desc from all controllers';

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];

        $userSYSTEM = User::whereLogin('SYSTEM')->first();

        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("Controllers and processed functions : ");
        // Affichage des permissions extraites
        foreach ($permissions as $controller => $methods) {
            $this->info("Controller: $controller :");
            foreach ($methods as $method => $perm) {

                if (empty($perm['permission']) && empty($perm['permission_desc'])) {
                    $this->info("-----No permission for this method: $method");
                    continue;
                }

                if (Permission::where('name', $perm['permission'])->exists()) {
                    $this->info("-----Permission exist for this method: $method");
                    continue;
                }

                $role = Role::find(1);

                $permission = Permission::create([
                    'name' => $perm['permission'],
                    'description' => $perm['permission_desc'],
                    'system' => true,
                    'created_by' => $userSYSTEM->id,
                    'updated_by' => $userSYSTEM->id
                ]);

                $this->info("-----Permission created for this method: $method");

                if ($role) {
                    $role->permissions()->syncWithPivotValues([$permission->id], [
                        'created_by' => $userSYSTEM->id,
                        'updated_by' => $userSYSTEM->id
                    ], false);
                    $this->info("-----Permission ajoutée au role : $role->name");
                }
            }
        }
    }

    private function getControllers($directory, $namespace = 'App\\Http\\Controllers'): array
    {
        $controllers = [];
        $files = scandir($directory);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                // Appel récursif pour les sous-dossiers
                $subNamespace = $namespace . '\\' . $file;
                $controllers = array_merge($controllers, $this->getControllers($fullPath, $subNamespace));
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $className = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);
                $controllers[] = $className;
            }
        }

        return $controllers;
    }

    private function extractPermissionsFromController($controller, &$permissions): void
    {
        if (!class_exists($controller)) {
            return;
        }

        $reflection = new ReflectionClass($controller);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $docComment = $method->getDocComment();
            if ($docComment) {
                $permission = $this->extractTagValue($docComment, '@permission');
                $permissionDesc = $this->extractTagValue($docComment, '@permission_desc');

                if ($permission || $permissionDesc) {
                    $permissions[$controller][$method->getName()] = [
                        'permission' => $permission,
                        'permission_desc' => $permissionDesc,
                    ];
                }
            }
        }
    }

    private function extractTagValue($doc, $tag): ?string
    {
        if (preg_match('/' . preg_quote($tag) . '\s+(.+)/', $doc, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}

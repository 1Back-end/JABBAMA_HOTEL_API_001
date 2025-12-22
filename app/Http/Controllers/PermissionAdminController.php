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

        $userSYSTEM = User::where('login', 'SYSTEM')->first();
        $role = Role::find(1);

        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $allControllerPermissions = collect($permissions)
            ->flatMap(fn($methods) => collect($methods)->pluck('permission'))
            ->filter()
            ->toArray();

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

                if ($role && !$role->permissions->contains($permission->id)) {
                    $role->permissions()->attach($permission->id, [
                        'created_by' => $userSYSTEM->id,
                        'updated_by' => $userSYSTEM->id
                    ]);
                }
            }
        }

        $toDelete = Permission::whereNotIn('name', $allControllerPermissions)
            ->where('system', true)
            ->get();

        foreach ($toDelete as $perm) {
            $perm->delete();
        }

        return response()->json(['status' => 'success', 'message' => '✅ Synchronisation des permissions réussie !']);
    }

    // --- Méthodes privées ---
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
    //
}

<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\PermissionRequest;
use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
/**
 * @permission_category Gestion des permissions
 * @permission_module Gestion des stocks
 * @code_module STOCK
 * @permission_module Gestion du restaurant
 * @code_module RESTAURANT
 * @permission_module Gestion du bar
 * @code_module BAR
 */
class PermissionController extends Controller
{
    /**
     * @return JsonResponse
     *
     * @permission PermissionController::index
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher les permissions
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get("per_page");
        $page = $request->get("page");
        $search = $request->get("search");

        $query = Permission::with([
            'roles:id,name',
            'createdBy:id,nom_utilisateur',
            'updatedBy:id,nom_utilisateur',
            'category:id,libelle',
        ]);

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('guard_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $query->orderBy('name', 'asc');
        if ($perPage && $page) {
            $permissions = $query->paginate(perPage: $perPage, page: $page);
        } else {
            $permissions = $query->get();
        }

        return response()->json([
            'permissions' => $permissions,
        ]);
    }


    /**
     * @return JsonResponse
     *
     * @permission PermissionController::permissionsByCategory
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher les permissions par catégories
     */
    public function permissionsByCategory(Request $request): JsonResponse
    {
        \Log::info('--- DEBUT permissionsByCategory ---');
        \Log::info('Paramètres bruts reçus :', $request->all());

        $moduleUuids = $request->input('module_uuids');

        if (is_string($moduleUuids)) {
            $moduleUuids = array_values(array_filter(explode(',', $moduleUuids)));
        } elseif (!is_array($moduleUuids)) {
            $moduleUuids = [];
        } else {
            $moduleUuids = array_values(array_filter($moduleUuids));
        }

        $moduleCodes = [];
        if (!empty($moduleUuids)) {
            $moduleCodes = \DB::table('modules_applications')
                ->whereIn('uuid', $moduleUuids)
                ->whereNotNull('code')
                ->pluck('code')
                ->toArray();
        }

        if (empty($moduleCodes) && !empty($moduleUuids)) {
            $moduleCodes = $moduleUuids;
        }

        \Log::info('Codes de modules identifiés pour le filtre :', $moduleCodes);

        // Définition de la règle de filtrage conditionnelle
        $filterPermissions = function ($query) use ($moduleCodes) {
            $query->whereNotNull('code');

            // Si des modules sont spécifiés, on applique le filtre LIKE / OR
            if (!empty($moduleCodes)) {
                $query->where(function ($q) use ($moduleCodes) {
                    foreach ($moduleCodes as $index => $moduleCode) {
                        $code = trim((string) $moduleCode);
                        if ($code === '') continue;

                        $method = $index === 0 ? 'where' : 'orWhere';

                        $q->{$method}(function ($sub) use ($code) {
                            $sub->where('code', '=', $code)
                                ->orWhere('code', 'LIKE', "{$code},%")
                                ->orWhere('code', 'LIKE', "%, {$code}%")
                                ->orWhere('code', 'LIKE', "%,{$code}%");
                        });
                    }
                });
            }
            // S'il n'y a aucun module, on ne met aucun filtre supplémentaire -> tout passe !
        };

        $categoriesQuery = PermissionCategory::whereNotNull('libelle')
            ->where('libelle', '!=', 'Autres');

        // Si des modules sont demandés, on filtre aussi les catégories pour ne garder que celles qui ont des permissions correspondantes
        if (!empty($moduleCodes)) {
            $categoriesQuery->whereHas('permissions', $filterPermissions);
        }

        $categories = $categoriesQuery
            ->with(['permissions' => function ($query) use ($filterPermissions) {
                $query->orderBy('name', 'asc');
                $filterPermissions($query);
            }])
            ->orderBy('libelle', 'asc')
            ->get();

        \Log::info('Nombre de catégories trouvées : ' . $categories->count());
        \Log::info('--- FIN permissionsByCategory ---');

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * @param PermissionRequest $request
     * @return JsonResponse
     *
     * @permission PermissionController::store
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Créer une permission
     */
    public function store(PermissionRequest $request)
    {
        Permission::create($request->validated());

        return response()->json([
            'message' => __("Permission crée avec succès !")
        ]);
    }

    /**
     * @param Permission $permission
     * @return JsonResponse
     *
     * @permission PermissionController::show
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher une permission
     */
    public function show(Permission $permission)
    {
        return response()->json($permission);
    }

    /**
     * @param Permission $permission
     * @param PermissionRequest $request
     * @return JsonResponse
     *
     * @permission PermissionController::update
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Mise à jour d’une permission
     */
    public function update(PermissionRequest $request, Permission $permission)
    {
        $data = $permission->system ? $request->except('name') : $request->validated();

        $permission->update($data);

        return response()->json([
            'message' => __("Permission mis à jour avec succès !")
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @param Permission $permission
     * @param int $activate
     * @return JsonResponse
     *
     * @permission PermissionController::activate
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Activer / Désactiver une permission
     */
    public function activate(Permission $permission, int $activate)
    {
        $permission->update([
            'active' => $activate,
        ]);

        return \response()->json([
            'message' => __("Le permission a été " . $activate ? 'activé' : 'désactivé' . " avec succès !"),
        ]);
    }

    /**
     * @param Permission $permission
     * @param Request $request
     * @return JsonResponse
     *
     * @permission PermissionController::assignPermissionToRole
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Assigner une permission à un ou plusieurs roles
     */
    public function assignPermissionToRole(Permission $permission, Request $request)
    {
        $request->validate([
            'role_ids' => ['required', 'array'],
        ]);

        $permission->permissions()->syncWithPivotValues($request->input('role_ids'), [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id()
        ], false);

        return \response()->json([
            'message' => __("Roles assignées avec succès !")
        ]);
    }

    /**
     * @param Role $role
     * @param Permission $permission
     * @param int $activate
     * @return JsonResponse
     *
     * @permission PermissionController::activatePermissionToRole
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Activate / Désactiver une permission à un rôle
     */
    public function activatePermissionToRole(Role $role, Permission $permission, int $activate)
    {
        $permission->permissions()->updateExistingPivot($role->id, [
            'active' => $activate,
            'updated_by' => auth()->id()
        ]);

        return \response()->json([
            'message' => __("Le Role a été " . $activate ? 'activé' : 'désactivé' . " avec succès pour cette permission !"),
        ]);
    }

    /**
     * @param Permission $permission
     * @param Request $request
     * @return JsonResponse
     *
     * @permission PermissionController::assignPermissionToUser
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Assigner un rôle à un ou plusieurs utilisateurs
     */
    public function assignPermissionToUser(Permission $permission, Request $request)
    {
        $request->validate([
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['required', 'exists:users,id'],
            'permissions' => ['array'],
        ]);

        foreach (User::findMany($request->input('user_ids')) as $user) {
            $user->permissions()->detach();
            foreach ($request->input('permissions') as $permission) {
                if($permission['centres']) {
                    foreach ($permission['centres'] as $centre_id) {
                        $user->permissions()->attach($permission['id'], [
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                            'centre_id' => $centre_id,
                        ]);
                    }
                }
                else {
                    $user->permissions()->attach($permission['id'], [
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ]);
                }
            }
        }

        return \response()->json([
            'message' => __("Permissions ont été assignées avec succès à ces utilisateurs!")
        ]);
    }

    /**
     * @param Permission $permission
     * @param User $user
     * @param int $activate
     * @return JsonResponse
     *
     * @permission PermissionController::activatePermissionToUser
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Activate / Désactiver une permission à un utilisateur
     */
    public function activatePermissionToUser(Permission $permission, User $user, int $activate)
    {
        $permission->permissions()->updateExistingPivot($user->id, [
            'active' => $activate,
            'updated_by' => auth()->id()
        ]);

        return \response()->json([
            'message' => __("La permission a été " . $activate ? 'activée' : 'désactivée' . " avec succès pour cet utilisateur !"),
        ]);
    }
}

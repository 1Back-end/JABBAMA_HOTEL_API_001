<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\RoleRequest;
use App\Models\Centre;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * @permission_category Gestion des roles
 * @permission_module Gestion des stocks
 * @permission_module Gestion du restaurant
 */
class RoleController extends Controller
{
    /**
     * @return JsonResponse
     *
     * @permission RoleController::index
     * @permission_desc Liste de tous les roles
     */
    public function index(Request $request): JsonResponse
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = Role::with([
            'createdBy:id,nom_utilisateur',
            'updatedBy:id,nom_utilisateur',
            'permissions:id,name,description',
        ]);

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('guard_name', 'like', "%{$search}%")

                    // 🔹 Permissions
                    ->orWhereHas('permissions', function ($qw) use ($search) {
                        $qw->where('id', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
            });
        }

        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);

    }

    /**
     * @param RoleRequest $request
     * @return JsonResponse
     *
     * @permission RoleController::store
     * @permission_desc Créer un role
     * @throws \Throwable
     */
    public function store(RoleRequest $request)
    {
        DB::beginTransaction();
        try {
            $roleData = $request->validated();
            $roleData['name'] = strtoupper($roleData['name']);
            $role = Role::create($roleData);
            if ($request->input('permissions')) {
                foreach ($request->input('permissions') as $permission) {
                    $role->permissions()->attach($permission['id'], [
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return response()->json([
                'message' => __("Une erreur s'est produite lors de la création du rôle !")
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        DB::commit();

        return response()->json([
            'message' => __("Le rôle a été créé avec succès !")
        ], Response::HTTP_CREATED);
    }


    /**
     * @param Role $role
     * @return JsonResponse
     *
     * @permission RoleController::show
     * @permission_desc Afficher un role
     */
    public function show(Role $role)
    {
        return response()->json([
            'role' => $role->load(['permissions:id,name']),
        ]);
    }

    /**
     * @param RoleRequest $request
     * @param Role $role
     * @return JsonResponse
     *
     * @permission RoleController::update
     * @permission_desc Mise à jour d’un rôle
     * @throws \Throwable
     */
    public function update(RoleRequest $request, Role $role)
    {
        DB::beginTransaction();

        try {

            $data = $request->validated();

            /*
            |--------------------------------------------------------------------------
            | PROTECTION ROLE ADMIN (id = 1)
            |--------------------------------------------------------------------------
            */

            if ($role->id === 1) {
                unset($data['name']);
            } else {
                if (isset($data['name'])) {
                    $data['name'] = strtoupper($data['name']);
                }
            }

            $role->update($data);

            /*
            |--------------------------------------------------------------------------
            | PERMISSIONS
            |--------------------------------------------------------------------------
            */

            $permissions = collect($request->input('permissions', []))
                ->pluck('id')
                ->toArray();
            $role->permissions()->detach();

            $now = now();
            $userId = auth()->id();

            $attachData = [];

            foreach ($permissions as $permissionId) {
                $attachData[$permissionId] = [
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($attachData)) {
                $role->permissions()->attach($attachData);
            }

            app()[\Spatie\Permission\PermissionRegistrar::class]
                ->forgetCachedPermissions();

            DB::commit();

            return response()->json([
                'message' => __("Mise à jour du rôle effectuée avec succès !"),
                'role' => $role->fresh('permissions'),
                'validated' => $request->validated()
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error($e->getMessage());

            return response()->json([
                'message' => __("Une erreur s'est produite lors de la mise à jour du rôle !")
            ], 500);
        }
    }


    /**
     * @param Role $role
     * @param int $activate
     * @return JsonResponse
     *
     * @permission RoleController::activate
     * @permission_desc Désactiver / Activer un rôle
     */
    public function activate(Role $role, int $activate)
    {
        $role->update([
            'active' => $activate,
        ]);

        return \response()->json([
            'message' => __("Le role a été " . $activate ? 'activé' : 'désactivé' . " avec succès !"),
        ]);
    }

    /**
     * @param Role $role
     * @param Request $request
     * @return JsonResponse
     *
     * @permission RoleController::assignRoleToPermission
     * @permission_desc Assigner un rôle à un ou plusieurs permissions
     */
    public function assignRoleToPermission(Role $role, Request $request)
    {
        $request->validate([
            'permission_ids' => ['required', 'array'],
        ]);

        $role->permissions()->syncWithPivotValues($request->input('permission_ids'), [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id()
        ], false);

        return \response()->json([
            'message' => __("Permissions assignées avec succès !")
        ]);
    }

    /**
     * @param Role $role
     * @param Permission $permission
     * @param int $activate
     * @return JsonResponse
     *
     * @permission RoleController::activateRoleToPermission
     * @permission_desc Activate / Désactiver un rôle à une permission
     */
    public function activateRoleToPermission(Role $role, Permission $permission, int $activate)
    {
        $role->permissions()->updateExistingPivot($permission->id, [
            'active' => $activate,
            'updated_by' => auth()->id()
        ]);

        return \response()->json([
            'message' => __("La permission a été " . $activate ? 'activé' : 'désactivé' . " avec succès pour ce rôle !"),
        ]);
    }

    /**
     * @param Role $role
     * @param Request $request
     * @return JsonResponse
     *
     * @permission RoleController::assignRoleToUser
     * @permission_desc Assigner un rôle à un ou plusieurs utilisateurs
     */
    public function assignRoleToUser(Role $role, Request $request)
    {
        $request->validate([
            'user_ids' => ['required', 'array'],
        ]);

        $role->users()->syncWithPivotValues($request->input('user_ids'), [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id()
        ], false);

        return \response()->json([
            'message' => __("Rôle assigné avec succès !")
        ]);
    }

    /**
     * @param Role $role
     * @param User $user
     * @param int $activate
     * @return JsonResponse
     *
     * @permission RoleController::activateRoleToUser
     * @permission_desc Activate / Désactiver un rôle à un utilisateur
     */
    public function activateRoleToUser(Role $role, User $user, int $activate)
    {
        $role->permissions()->updateExistingPivot($user->id, [
            'active' => $activate,
            'updated_by' => auth()->id()
        ]);

        return \response()->json([
            'message' => __("Le rôle a été " . $activate ? 'activé' : 'désactivé' . " avec succès pour cet utilisateur !"),
        ]);
    }
}

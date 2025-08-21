<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\PermissionRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PermissionController extends Controller
{
    /**
     * @return JsonResponse
     *
     * @permission PermissionController::index
     * @permission_desc Afficher les permissions
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get("per_page");
        $page = $request->get("page");

        if ($perPage && $page) {
            $permissions = Permission::with([
                'roles:id,name',
                'createdBy:id,nom_utilisateur',
                'updatedBy:id,nom_utilisateur',
                'menu:id,libelle',
            ])->paginate(perPage: $perPage, page: $page);
        } else {
            $permissions = Permission::with([
                'roles:id,name',
                'createdBy:id,nom_utilisateur',
                'updatedBy:id,nom_utilisateur',
            ])->get();
        }

        return response()->json([
            'permissions' => $permissions,
        ]);
    }

    /**
     * @param PermissionRequest $request
     * @return JsonResponse
     *
     * @permission PermissionController::store
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

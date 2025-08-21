<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\MenuRequest;
use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuController extends Controller
{
    /**
     * @return JsonResponse
     *
     * @permission MenuController::index
     * @permission_desc Liste des Menus
     */
    public function index(Request $request)
    {
        return response()->json([
            'menus' => Menu::with(['permission:id,name,description,menu_id', 'createdBy:id,nom_utilisateur', 'updatedBy:id,nom_utilisateur',])->paginate(
                perPage: $request->input('per_page', 10),
                page: $request->input('page', 1)
            )
        ]);
    }


    /**
     * @return JsonResponse
     *
     * @permission MenuController::store
     * @permission_desc Créer un menu et l’associé avec des permissions
     */
    public function store(MenuRequest $request)
    {
        $menu = Menu::create($request->except('permission'));

        Permission::find($request->input('permission'))
            ->update(['menu_id' => $menu->id]);

        return response()->json([
            'message' => __("Menu crée avec success !")
        ], Response::HTTP_CREATED);
    }

    /**
     * Verifies qu’une permission est associée au menu
     *
     * @permission MenuController::store
     * @permission_desc Créer un menu et l’associé avec des permissions
     */
    public function checkMenu(Request $request): JsonResponse
    {
        $request->validate([
            'permission' => ['required', 'exists:permissions,id'],
            'menu_id' => ['nullable', 'exists:menus,id']
        ]);

        $permission = Permission::find($request->input('permission'));

        return \response()->json([
            'menu' => $request->input('menu_id') && $permission->menu_id === $request->input('menu_id') ? null : $permission->menu
        ]);
    }

    /**
     * @return JsonResponse
     *
     * @permission MenuController::show
     * @permission_desc Afficher un menu et des permissions associées
     */
    public function show(Menu $menu)
    {
        return \response()->json([
            'menu' => $menu->load('permissions:id,name,description')
        ]);
    }

    /**
     * @return JsonResponse
     *
     * @permission MenuController::update
     * @permission_desc Mise à jou d’un menu et l’associé avec des permissions
     */
    public function update(MenuRequest $request, Menu $menu)
    {
        $menu->update($request->except('permission'));

        if ($menu->permission) {
            $menu->permission->update(['menu_id' => $menu->id]);
        }
        else {
            Permission::find($request->input('permission'))
                ->update(['menu_id' => $menu->id]);
        }

        return response()->json([
            'message' => __("Mise à jour effectuée avec succès !")
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @return JsonResponse
     *
     * @permission MenuController::activate
     * @permission_desc Activer / Désactiver un menu
     */
    public function activate(Menu $menu, int $activate)
    {
        $menu->update(['active' => $activate]);

        return response()->json([
            'message' => __("Le Menu a été " . $activate ? 'activé' : 'désactivé' . " avec succès !")
        ], Response::HTTP_ACCEPTED);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\WarehouseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
/**
 * @permission_category Gestion des entrepôts
 */
class WarehouseController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission WarehouseController::index
     * @permission_desc Afficher la liste des entrepôts
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Warehouse::with(['creator','updater','natures','managers'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('stock_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }
        $warehouse = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $warehouse->items(),
            'current_page' => $warehouse->currentPage(),
            'last_page'    => $warehouse->lastPage(),
            'total'        => $warehouse->total(),
        ]);
        //
    }

    /**
     * Display a listing of the resource.
     * @permission WarehouseController::store
     * @permission_desc Création des entrepôts
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        // ✅ Validation des données
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:warehouses,name',
            'stock_type'  => 'required|string|max:255',
            'address'     => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'managers'    => 'required|array|min:1',
            'managers.*'  => 'required|exists:users,id',
            'natures'     => 'required|array|min:1',
            'natures.*'   => 'required|exists:nature_entrepots,uuid',
        ], [
            'name.required'       => "Le nom de l'entrepôt est obligatoire.",
            'name.unique'         => "Un entrepôt avec ce nom existe déjà.",
            'stock_type.required' => "Le type de stock est obligatoire.",
            'managers.required'   => "Veuillez sélectionner au moins un manager.",
            'managers.*.exists'   => "Un des managers sélectionnés est invalide.",
            'natures.required'    => "Veuillez sélectionner au moins une nature.",
            'natures.*.exists'    => "Une des natures sélectionnées est invalide.",
        ]);

        $validated['created_by'] = $auth->id;

        // ✅ Création de l'entrepôt
        $warehouse = Warehouse::create($validated);

        // ✅ Association des natures (pivot avec métadonnées)
        $natures = collect($validated['natures'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'is_active'  => true,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);

        $warehouse->natures()->sync($natures);

        // ✅ Association des managers (pivot avec métadonnées)
        $managers = collect($validated['managers'])->mapWithKeys(fn($managerId) => [
            $managerId => [
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);

        $warehouse->managers()->sync($managers);

        // ✅ Réponse JSON
        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été créé avec succès.",
            'data'    => $warehouse->load(['creator', 'updater', 'natures', 'managers']),
        ], 201);
    }





    /**
     * Display a listing of the resource.
     * @permission WarehouseController::show
     * @permission_desc Afficher les détails des entrepôts
     */
    public function show(string $uuid)
    {
        $warehouse = Warehouse::findOrFail($uuid);

        return response()->json([
            'success' => true,
            'message' => "Détails de l'entrepôt récupérés avec succès.",
            'data'    => $warehouse
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::update
     * @permission_desc Modification des entrepôts
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // ✅ Récupération de l'entrepôt
        $warehouse = Warehouse::where('uuid', $uuid)->firstOrFail();

        // ✅ Validation des données
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:warehouses,name,' . $warehouse->uuid . ',uuid',
            'stock_type'  => 'required|string|max:255',
            'address'     => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'managers'    => 'required|array|min:1',
            'managers.*'  => 'required|exists:users,id',
            'natures'     => 'required|array|min:1',
            'natures.*'   => 'required|exists:nature_entrepots,uuid',
        ], [
            'name.required'       => "Le nom de l'entrepôt est obligatoire.",
            'name.unique'         => "Un entrepôt avec ce nom existe déjà.",
            'stock_type.required' => "Le type de stock est obligatoire.",
            'managers.required'   => "Veuillez sélectionner au moins un manager.",
            'managers.*.exists'   => "Un des managers sélectionnés est invalide.",
            'natures.required'    => "Veuillez sélectionner au moins une nature.",
            'natures.*.exists'    => "Une des natures sélectionnées est invalide.",
        ]);

        // ✅ Mise à jour des informations principales
        $warehouse->update([
            'name'        => $validated['name'],
            'stock_type'  => $validated['stock_type'],
            'address'     => $validated['address'] ?? null,
            'description' => $validated['description'] ?? null,
            'updated_by'  => $auth->id,
        ]);

        // ✅ Mise à jour des natures (pivot)
        $natures = collect($validated['natures'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'is_active'  => true,
                'updated_by' => $auth->id,
            ],
        ]);
        $warehouse->natures()->sync($natures);

        // ✅ Mise à jour des managers (pivot)
        $managers = collect($validated['managers'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'updated_by' => $auth->id,
            ],
        ]);
        $warehouse->managers()->sync($managers);

        // ✅ Réponse JSON
        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été mis à jour avec succès.",
            'data'    => $warehouse->load(['creator', 'updater', 'natures', 'managers']),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission WarehouseController::destroy
     * @permission_desc Suppression des entrepôts
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $warehouse = Warehouse::findOrFail($uuid);

        if ($warehouse->orders()->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de supprimer cet entrepot : des commandes lui sont associées.'
            ], 422);
        }

        $warehouse->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été supprimé avec succès."
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::update_status
     * @permission_desc Activation/Désactivation des entrepôts
     */
    public function update_status(Request $request, $uuid){
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $warehouse = Warehouse::findOrFail($uuid);
        $warehouse->is_active = $request->is_active;
        $warehouse->updated_by = $auth->id;
        $warehouse->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
    }

    public function get_all_warehouses_by_users(Request $request)
    {
        $auth = auth()->user();

        $warehouses = Warehouse::whereHas('managers', function ($query) use ($auth) {
            $query->where('users.id', $auth->id);
        })
            ->with([
                'natures',
                'managers',
                'products' => function($query) {
                    $query->wherePivot('is_active', true); // uniquement produits actifs
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des entrepôts et leurs produits pour l’utilisateur connecté.',
            'data'    => $warehouses,
        ]);
    }

    public function get_products_by_warehouse(string $uuid, Request $request)
    {
        $search = $request->query('search', '');
        $is_active = $request->query('is_active', null);

        $warehouse = Warehouse::with(['products' => function($query) use ($search, $is_active) {
            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }
            if (!is_null($is_active)) {
                $query->where('is_active', $is_active);
            }
        }])->findOrFail($uuid);

        return response()->json([
            'success' => true,
            'message' => "Produits de l’entrepôt récupérés avec succès.",
            'data'    => $warehouse->products,
        ]);
    }


}

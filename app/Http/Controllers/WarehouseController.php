<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        $query = Warehouse::with(['creator','updater','natures','manager'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('nature', 'like', "%{$search}%")
                    ->orWhere('stock_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
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

        // Validation principale
        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:warehouses,name',
            'stock_type'   => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'manager_id'   => 'required|exists:users,id',
            'natures'      => 'required|array|min:1',           // tableau d'UUID de natures
            'natures.*'    => 'required|exists:nature_entrepots,uuid',
            'description'  => 'nullable|string|max:255',
        ], [
            'name.required'       => "Le nom de l'entrepôt est obligatoire.",
            'name.unique'         => "Un entrepôt avec ce nom existe déjà.",
            'stock_type.required' => "Le type de stock disponible est obligatoire.",
            'natures.required'    => "Veuillez sélectionner au moins une nature pour l'entrepôt.",
            'natures.*.exists'    => "Une des natures sélectionnées est invalide.",
            'manager_id.required' => "Le manager est obligatoire.",
        ]);

        // Ajout de l'auteur
        $validated['created_by'] = $auth->id;

        // Création de l'entrepôt
        $warehouse = Warehouse::create($validated);

        // Association des natures via pivot
        $natures = collect($validated['natures'])->mapWithKeys(function($nature_uuid) use ($auth) {
            return [$nature_uuid => [
                'created_by' => $auth->id,
            ]];
        });
        $warehouse->natures()->sync($natures);

        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été créé avec succès.",
            'data'    => $warehouse->load('natures', 'manager')
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

        $warehouse = Warehouse::findOrFail($uuid);

        // Validation
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:warehouses,name,' . $warehouse->uuid . ',uuid',
            'stock_type'  => 'required|string|max:255',
            'address'     => 'nullable|string|max:255',
            'manager_id'  => 'required|exists:users,id',
            'natures'     => 'required|array|min:1',          // tableau d'UUID de natures
            'natures.*'   => 'required|exists:nature_entrepots,uuid',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'sometimes|boolean'
        ], [
            'name.required'       => "Le nom de l'entrepôt est obligatoire.",
            'name.unique'         => "Un autre entrepôt avec ce nom existe déjà.",
            'stock_type.required' => "Le type de stock disponible est obligatoire.",
            'natures.required'    => "Veuillez sélectionner au moins une nature pour l'entrepôt.",
            'natures.*.exists'    => "Une des natures sélectionnées est invalide.",
            'manager_id.required' => "Le manager est obligatoire.",
        ]);

        $validated['updated_by'] = $auth->id;

        // Mise à jour de l'entrepôt
        $warehouse->update($validated);

        // Mise à jour des natures via pivot
        $natures = collect($validated['natures'])->mapWithKeys(function($nature_uuid) use ($auth) {
            return [$nature_uuid => [
                'updated_by' => $auth->id,
            ]];
        });
        $warehouse->natures()->sync($natures);

        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été mis à jour avec succès.",
            'data'    => $warehouse->load('natures', 'manager')
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

        $warehouse->delete();

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
}

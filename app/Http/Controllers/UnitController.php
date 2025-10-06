<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UnitController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission UnitController::index
     * @permission_desc Afficher la liste des unités de produits
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Unit::with(['creator','updater']);

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $units = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $units->items(),
            'current_page' => $units->currentPage(),
            'last_page'    => $units->lastPage(),
            'total'        => $units->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission UnitController::store
     * @permission_desc Création des unités de produits
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:units,name',
            'abbreviation' => 'required|string|max:255',
            'description'  => 'nullable|string|max:255',
        ], [
            'name.required'    => 'Le nom est obligatoire.',
            'name.unique'      => 'Cette unité existe déjà.',
            'abbreviation.required' => "L'abréviation est obligatoire.",
        ]);

        $validated['created_by'] = $auth->id;

        $unit = Unit::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Création effectuée avec succès',
            'data'    => $unit
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission UnitController::update
     * @permission_desc Modification des unités de produits
     */

    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Récupérer l'unité à mettre à jour
        $unit = Unit::findOrFail($uuid);

        // Validation des données avec unicité sur le name sauf pour l'élément actuel
        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:units,name,' . $unit->uuid . ',uuid',
            'abbreviation' => 'required|string|max:255',
            'description'  => 'nullable|string|max:255',
        ], [
            'name.required'    => 'Le nom est obligatoire.',
            'name.unique'      => 'Cette unité existe déjà.',
            'abbreviation.required' => "L'abréviation est obligatoire.",
        ]);

        // Ajouter l'utilisateur qui met à jour
        $validated['updated_by'] = $auth->id;

        // Mise à jour
        $unit->update($validated);

        return response()->json([
            'success' => true,
            'message' => "Mise à jour effectuée avec succès",
            'data'    => $unit
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission UnitController::show
     * @permission_desc Afficher les détails des unités de produits
     */
    public function show(string $uuid)
    {
        $unit = Unit::findOrFail($uuid);

        return response()->json([
            'success' => true,
            'data' => $unit
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission UnitController::destroy
     * @permission_desc Suppression des unités de produits
     */
    public function destroy(Request $request, string $uuid)
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

        try {
            $unit = Unit::findOrFail($uuid);

            // Vérifier si l'unité est utilisée dans des produits
            $isUsed = Product::where('unit_measure_uuid', $uuid)->exists();
            if ($isUsed) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Impossible de supprimer cette unité. Elle est utilisée par un ou plusieurs produits."
                ], 409); // 409 Conflict
            }

            $unit->delete();

            return response()->json([
                'success' => true,
                'message' => "L'unité '{$unit->name}' a été supprimée avec succès."
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unité introuvable.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de supprimer l\'unité pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission UnitController::update_status
     * @permission_desc Activer/Désactiver des unités de produits
     */
    public function update_status(Request $request, $uuid){
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $unit = Unit::findOrFail($uuid);
        $unit->is_active = $request->is_active;
        $unit->updated_by = $auth->id;
        $unit->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
    }


}

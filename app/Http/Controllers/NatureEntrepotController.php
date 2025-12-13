<?php

namespace App\Http\Controllers;

use App\Models\NatureEntrepot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
/**
 * @permission_category Gestion des natures d'entrepôts
 */
class NatureEntrepotController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission NatureEntrepotController::index
     * @permission_desc Afficher la liste des natures d'entrepôts
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = NatureEntrepot::with(['creator','updater'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });
        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%")
                    ->orWhere('is_active', 'like', "%{$search}%");
            });
        }
        $nature = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $nature->items(),
            'current_page' => $nature->currentPage(),
            'last_page'    => $nature->lastPage(),
            'total'        => $nature->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NatureEntrepotController::store
     * @permission_desc Créer des natures d'entrepôts
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        // Validation
        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:nature_entrepots,name',
            'abbreviation' => 'required|string|max:255',
            'description'  => 'nullable|string|max:255',
        ], [
            'name.required'         => 'Le nom est obligatoire.',
            'abbreviation.required' => "L'abréviation est obligatoire.",
            'name.unique'           => "Le nom doit être unique.",
        ]);

        // Ajout de l'utilisateur créateur
        $validated['created_by'] = $auth->id;

        // Création
        $nature = NatureEntrepot::create($validated);

        // Retour JSON
        return response()->json([
            'success' => true,
            'message' => 'Création effectuée avec succès.',
            'data'    => $nature
        ], 201);
    }


    /**
     * Display a listing of the resource.
     * @permission NatureEntrepotController::show
     * @permission_desc Afficher les détails des natures d'entrepôts
     */
    public function show(string $uuid)
    {
        // Récupère la nature d'entrepôt ou renvoie 404
        $nature = NatureEntrepot::findOrFail($uuid);

        return response()->json([
            'success' => true,
            'message' => 'Nature d’entrepôt récupérée avec succès.',
            'data'    => $nature
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission NatureEntrepotController::update
     * @permission_desc Modifier des natures d'entrepôts
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        // Récupère la nature d'entrepôt
        $nature = NatureEntrepot::findOrFail($uuid);

        // Validation
        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:nature_entrepots,name,' . $nature->uuid . ',uuid',
            'abbreviation' => 'required|string|max:255',
            'description'  => 'nullable|string|max:255',
            'is_active'    => 'nullable|boolean',
        ], [
            'name.required'         => 'Le nom est obligatoire.',
            'abbreviation.required' => "L'abréviation est obligatoire.",
            'name.unique'           => "Le nom doit être unique.",
        ]);

        // Ajout de l'utilisateur qui met à jour
        $validated['updated_by'] = $auth->id;

        // Mise à jour
        $nature->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Nature d’entrepôt mise à jour avec succès.',
            'data'    => $nature
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NatureEntrepotController::destroy
     * @permission_desc Supprimer des natures d'entrepôts
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
        // Récupère la nature d'entrepôt ou renvoie 404
        $nature = NatureEntrepot::findOrFail($uuid);

        // Suppression soft
        $nature->delete();

        return response()->json([
            'success' => true,
            'message' => 'Nature d’entrepôt supprimée avec succès.'
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NatureEntrepotController::update_status
     * @permission_desc Activer/Désactiver des natures d'entrepôts
     */
    public function update_status(string $uuid, Request $request)
    {
        $auth = auth()->user();

        // Récupère la nature d'entrepôt ou renvoie 404
        $nature = NatureEntrepot::findOrFail($uuid);

        // Validation du statut
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'Le statut est obligatoire.',
            'is_active.boolean'  => 'Le statut doit être true ou false.',
        ]);

        // Mise à jour du statut et de l'utilisateur
        $nature->update([
            'is_active'  => $validated['is_active'],
            'updated_by' => $auth->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $validated['is_active'] ? 'Nature d’entrepôt activée.' : 'Nature d’entrepôt désactivée.',
            'data'    => $nature
        ]);
    }

}

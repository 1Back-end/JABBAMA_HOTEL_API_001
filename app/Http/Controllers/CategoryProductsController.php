<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
/**
 * @permission_category Gestion des catégories d'articles
 */
class CategoryProductsController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission CategoryProductsController::index
     * @permission_desc Afficher la liste des catégories d'articles
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Category::with(['creator','updater','subCategories'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $category = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $category->items(),
            'current_page' => $category->currentPage(),
            'last_page'    => $category->lastPage(),
            'total'        => $category->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission CategoryProductsController::store
     * @permission_desc Création des catégories d'articles
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'abbreviation' => 'required|string|unique:categories,abbreviation',
            'name'        => 'required|string|max:150|unique:categories,name',
            'description' => 'nullable|string|max:255',
            'parent_uuid' => 'nullable|exists:categories,uuid',
        ], [
            'name.required' => 'Le nom de la catégorie est obligatoire.',
            'name.unique'   => 'Cette catégorie existe déjà.',
            'parent_uuid.exists' => 'La catégorie parent est invalide.',
            'abbreviation.required' => 'L\'abbrévation est obligatoire.',
            'abbrevation.unique' => 'L\'abbrévation existe déjà'
        ]);

        $validated['created_by'] = $auth->id;

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie enregistrée avec succès !',
            'data'    => $category
        ], 201);
    }


    /**
     * Display a listing of the resource.
     * @permission CategoryProductsController::show
     * @permission_desc Afficher les détails des catégories d'articles
     */
    public function show(string $uuid)
    {
        $category = Category::findOrFail($uuid);

        return response()->json([
            'success' => true,
            'data' => $category
        ]);
        //
    }

    /**
     * Display a listing of the resource.
     * @permission CategoryProductsController::update
     * @permission_desc Modification des catégories d'articles
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $category = Category::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name'        => 'required|string|max:150|unique:categories,name,' . $category->uuid . ',uuid',
            'description' => 'nullable|string|max:255',
            'parent_uuid' => 'nullable|exists:categories,uuid|not_in:' . $category->uuid,
        ], [
            'name.required' => 'Le nom de la catégorie est obligatoire.',
            'name.unique'   => 'Cette catégorie existe déjà.',
            'parent_uuid.exists' => 'La catégorie parent est invalide.',
            'parent_uuid.not_in' => 'Une catégorie ne peut pas être son propre parent.',
        ]);

        $validated['updated_by'] = $auth->id;

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie mise à jour avec succès !',
            'data'    => $category
        ], 200);
    }




    /**
     * Display a listing of the resource.
     * @permission CategoryProductsController::destroy
     * @permission_desc Suppression des catégories d'articles
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
            $category = Category::findOrFail($uuid);

            // Vérifier si la catégorie est utilisée dans des produits
            $isUsed = \App\Models\Product::where('category_uuid', $uuid)->exists();
            if ($isUsed) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Impossible de supprimer cette catégorie. Elle est utilisée par un ou plusieurs produits."
                ], 409); // 409 Conflict
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => "La catégorie '{$category->name}' a été supprimée avec succès."
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Catégorie introuvable.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer la catégorie pour le moment. Veuillez réessayer plus tard.",
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission CategoryProductsController::update_status
     * @permission_desc Activation/Désactivation des catégories d'articles
     */
    public function update_status(Request $request, $uuid){
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $category = Category::findOrFail($uuid);
        $category->is_active = $request->is_active;
        $category->updated_by = $auth->id;
        $category->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
    }
    public function get_by_category($category_uuid)
    {
        // Récupère toutes les sous-catégories actives de la catégorie
        $subCategories = SubCategory::where('category_uuid', $category_uuid)
            ->where('is_active', 1)
            ->get(['uuid', 'name']);

        return response()->json([
            'success' => $subCategories->count() > 0,
            'data' => $subCategories,
        ]);
    }


}

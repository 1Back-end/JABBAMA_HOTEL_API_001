<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductSubCategory;
use App\Models\PurchaseOrder;
use App\Models\SubCategory;
use Illuminate\Http\Request;

/**
 * @permission_category Gestion des sous catégories d'articles
 */
class SubCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission SubCategoryController::index
     * @permission_desc Afficher la liste des sous-catégories d'articles
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = SubCategory::with(['category','creator','updater'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('is_active', 'like', "%{$search}%");
            });
        }
        $sub_category = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $sub_category->items(),
            'current_page' => $sub_category->currentPage(),
            'last_page'    => $sub_category->lastPage(),
            'total'        => $sub_category->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission SubCategoryController::store
     * @permission_desc Création des sous-catégories d'articles
     */
    public function store(Request $request)
    {
        try {
            $auth = auth()->user();

            // Validation
            $validated = $request->validate([
                'sub_categories' => 'required|array|min:1',
                'sub_categories.*.name' => 'required|string|max:255',
                'sub_categories.*.description' => 'nullable|string|max:500',
                'sub_categories.*.children' => 'nullable|array',
                'category_uuid' => 'required|exists:categories,uuid',
            ], [
                'sub_categories.required' => 'Veuillez ajouter au moins une sous-catégorie.',
                'sub_categories.*.name.required' => 'Le nom de chaque sous-catégorie est obligatoire.',
                'category_uuid.required' => 'La catégorie est obligatoire.',
                'category_uuid.exists' => 'La catégorie sélectionnée est invalide.',
            ]);

            $created = [];
            $ignored = [];

            // Fonction récursive pour créer les sous-catégories
            $storeRecursive = function ($subs, $category_uuid, $parent_uuid = null) use (&$storeRecursive, $auth, &$created, &$ignored) {
                foreach ($subs as $sub) {
                    // Vérifier si la sous-catégorie existe déjà pour cette catégorie et parent
                    $existing = SubCategory::where('category_uuid', $category_uuid)
                        ->where('parent_uuid', $parent_uuid)
                        ->where('name', $sub['name'])
                        ->first();

                    if ($existing) {
                        $ignored[] = [
                            'name' => $sub['name'],
                            'parent_uuid' => $parent_uuid,
                            'category_uuid' => $category_uuid,
                        ];
                        continue; // ignorer
                    }

                    $subCategory = SubCategory::create([
                        'name' => $sub['name'],
                        'description' => $sub['description'] ?? null,
                        'category_uuid' => $category_uuid,
                        'parent_uuid' => $parent_uuid,
                        'created_by' => $auth->id,
                    ]);

                    $created[] = [
                        'uuid' => $subCategory->uuid,
                        'name' => $subCategory->name,
                        'parent_uuid' => $parent_uuid,
                    ];

                    // Appel récursif pour les enfants
                    if (!empty($sub['children'])) {
                        $storeRecursive($sub['children'], $category_uuid, $subCategory->uuid);
                    }
                }
            };

            // Lancer la récursion
            $storeRecursive($validated['sub_categories'], $validated['category_uuid']);

            return response()->json([
                'success' => true,
                'message' => 'Sous-catégories traitées avec succès.',
                'created_count' => count($created),
                'ignored_count' => count($ignored),
                'created' => $created,
                'ignored' => $ignored
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Erreur store sub_categories: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'enregistrement des sous-catégories.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }






    /**
     * Display a listing of the resource.
     * @permission SubCategoryController::show
     * @permission_desc Afficher les détails des sous-catégories d'articles
     */
    public function show(string $uuid)
    {
        $subcategory = SubCategory::with('category')
            ->where('uuid', $uuid)
            ->first();

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Sous-catégorie introuvable.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $subcategory
        ], 200);
    }

    /**
     * Display a listing of the resource.
     * @permission SubCategoryController::update_status
     * @permission_desc Activation/Désactivation des sous-catégories d'articles
     */
    public function update_status(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $subcategory = SubCategory::where('uuid', $uuid)->first();

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Sous-catégorie introuvable.'
            ], 404);
        }

        $subcategory->update([
            'is_active' => $validated['is_active'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $validated['is_active']
                ? 'Sous-catégorie activée avec succès.'
                : 'Sous-catégorie désactivée avec succès.',
            'data' => $subcategory
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission SubCategoryController::update
     * @permission_desc Mise à jour des sous-catégories d'articles
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        // Validation pour une seule sous-catégorie
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'category_uuid' => 'required|exists:categories,uuid',
        ], [
            'name.required' => 'Le nom de la sous-catégorie est obligatoire.',
            'category_uuid.required' => 'La catégorie est obligatoire.',
            'category_uuid.exists' => 'La catégorie sélectionnée est invalide.',
        ]);

        $subcategory = SubCategory::where('uuid', $uuid)->first();

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Sous-catégorie introuvable.'
            ], 404);
        }

        $subcategory->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category_uuid' => $validated['category_uuid'],
            'updated_by' => $auth->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sous-catégorie mise à jour avec succès.'
        ]);
    }



    /**
     * Display a listing of the resource.
     * @permission SubCategoryController::updateStatus
     * @permission_desc Suppression des sous-catégories d'articles
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Vérifier le mot de passe de l'utilisateur
        $request->validate([
            'password' => 'required|string'
        ]);

        if (!\Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $subcategory = SubCategory::where('uuid', $uuid)->first();

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Sous-catégorie introuvable.'
            ], 404);
        }

         //Vérifier si des contraintes existent (exemple: produits liés)
         $isUsed = ProductSubCategory::where('sub_category_uuid', $uuid)->exists();
         if ($isUsed) {
             return response()->json([
                 'success' => false,
                 'message' => 'Impossible de supprimer cette sous-catégorie, elle est utilisée.'
             ], 409);
         }

        // Supprimer définitivement la sous-catégorie (pas soft delete)
        $subcategory->delete();

        return response()->json([
            'success' => true,
            'message' => "Sous-catégorie '{$subcategory->name}' supprimée avec succès."
        ]);
    }



}

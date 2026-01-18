<?php

namespace App\Http\Controllers;

use App\Models\CategoryTree;
use App\Models\Product;
use App\Models\ProductSubCategory;
use App\Models\PurchaseOrder;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $query = CategoryTree::with(['category','creator','updater']);

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

    public function normalizeSubCategories(array $items): array
    {
        return collect($items)->map(function ($item) {
            return [
                'uuid'        => \Str::uuid()->toString(),
                'name'        => $item['name'],
                'description' => $item['description'] ?? null,
                'children'    => isset($item['children']) && is_array($item['children'])
                    ? $this->normalizeSubCategories($item['children'])
                    : [],
            ];
        })->values()->toArray();
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

            // 🔹 Validation
            $validated = $request->validate([
                'category_uuid'            => 'required|uuid|exists:categories,uuid',
                'sub_categories'           => 'required|array|min:1',
                'sub_categories.*.name'    => 'required|string|max:255',
                'sub_categories.*.children'=> 'nullable|array',
            ]);

            DB::beginTransaction();

            // 🔹 Normalisation récursive
            $children = $this->normalizeSubCategories($validated['sub_categories']);

            // 🔹 Upsert (1 arbre par catégorie)
            $tree = CategoryTree::updateOrCreate(
                ['category_uuid' => $validated['category_uuid']],
                [
                    'children'   => $children,
                    'updated_by' => $auth->id,
                    'created_by' => $auth->id,
                ]
            );

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Sous-catégories enregistrées avec succès',
                'data'    => $tree,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('CategoryTree store error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne lors de l’enregistrement',
                'error'   => $e->getMessage(), // 🔥 utile pour Postman
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

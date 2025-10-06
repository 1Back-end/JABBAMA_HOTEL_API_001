<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission ProductController::index
     * @permission_desc Afficher la liste des produits
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Product::with(['creator', 'updater', 'category', 'unitMeasure']);

        if ($request->filled('category_uuid')) $query->where('category_uuid', $request->category_uuid);
        if ($request->filled('unit_uuid')) $query->where('unit_uuid', $request->unit_uuid);

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                // Recherche sur les colonnes du produit
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('purchase_price', 'like', "%{$search}%")
                    ->orWhere('sale_price', 'like', "%{$search}%")
                    ->orWhere('stock_quantity', 'like', "%{$search}%")
                    ->orWhere('minimum_stock', 'like', "%{$search}%")

                    // Recherche dans l'unité de mesure
                    ->orWhereHas('unitMeasure', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('uuid', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('abbreviation', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    })

                    // Recherche dans la catégorie
                    ->orWhereHas('category', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('abbreviation', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
            });
        }

        $products = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::store
     * @permission_desc Création des produits
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        try {

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:produits,name',
                'description' => 'nullable|string',
                'category_uuid' => 'nullable|exists:categories,uuid',
                'unit_uuid' => 'nullable|exists:units,uuid',
                'purchase_price' => 'required|numeric|min:0',
                'sale_price' => 'required|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'minimum_stock' => 'required|integer|min:0',
            ], [
                'name.required' => 'Le nom du produit est obligatoire.',
                'name.unique' => 'Ce nom de produit existe déjà.',
                'purchase_price.required' => 'Le prix d\'achat est obligatoire.',
                'sale_price.required' => 'Le prix de vente est obligatoire.',
                'stock_quantity.required' => 'La quantité en stock est obligatoire.',
                'minimum_stock.required' => 'Le stock minimum est obligatoire.',
                'category_uuid.exists' => 'La catégorie sélectionnée n\'existe pas.',
                'unit_uuid.exists' => 'L\'unité de mesure sélectionnée n\'existe pas.',
            ]);

            // Définir l'auteur de la création
            $validated['created_by'] = $auth->id;

            $product = Product::create($validated);

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été créé avec succès !",
                'product' => $product
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les champs saisis.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de créer le produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ProductController::update_status
     * @permission_desc Activation/Désactivation des produits
     */
    public function update_status(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            $product = Product::findOrFail($uuid);

            $request->validate([
                'is_active' => 'required|boolean',
            ], [
                'is_active.required' => 'Le statut du produit est obligatoire.',
                'is_active.boolean' => 'Le statut doit être vrai ou faux.',
            ]);

            $product->is_active = $request->is_active;
            $product->updated_by = $auth->id;
            $product->save();

            $statusText = $product->is_active ? 'activé' : 'désactivé';

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été {$statusText} avec succès !",
                'product' => $product
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier le statut fourni.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable.',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de mettre à jour le statut du produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::show
     * @permission_desc Afficher les détails des produits
     */
    public function show($uuid)
    {
        try {
            $product = Product::with(['category', 'unitMeasure', 'creator', 'updater'])
                ->findOrFail($uuid);

            return response()->json([
                'success' => true,
                'message' => "Détails du produit '{$product->name}' récupérés avec succès.",
                'data' => $product
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable.',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les détails du produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::update
     * @permission_desc Modification des produits
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            $product = Product::findOrFail($uuid);

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:produits,name,' . $product->uuid . ',uuid',
                'description' => 'nullable|string',
                'category_uuid' => 'nullable|exists:categories,uuid',
                'unit_uuid' => 'nullable|exists:units,uuid',
                'purchase_price' => 'required|numeric|min:0',
                'sale_price' => 'required|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'minimum_stock' => 'required|integer|min:0',
            ], [
                'name.required' => 'Le nom du produit est obligatoire.',
                'name.unique' => 'Ce nom de produit existe déjà.',
                'purchase_price.required' => 'Le prix d\'achat est obligatoire.',
                'sale_price.required' => 'Le prix de vente est obligatoire.',
                'stock_quantity.required' => 'La quantité en stock est obligatoire.',
                'minimum_stock.required' => 'Le stock minimum est obligatoire.',
                'category_uuid.exists' => 'La catégorie sélectionnée n\'existe pas.',
                'unit_uuid.exists' => 'L\'unité de mesure sélectionnée n\'existe pas.',
            ]);

            $validated['updated_by'] = $auth->id;

            $product->update($validated);

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été mis à jour avec succès !",
                'product' => $product
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les champs saisis.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable.',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de mettre à jour le produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::destroy
     * @permission_desc Suppression des produits
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

        $product = Product::findOrFail($uuid);

        // Supprime l'entrepôt
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => "Produit a été supprimé avec succès."
        ]);
        //
    }
}

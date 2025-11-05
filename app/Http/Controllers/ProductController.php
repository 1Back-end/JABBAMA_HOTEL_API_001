<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @permission_category Gestion des articles
 */

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission ProductController::index
     * @permission_desc Afficher la liste des articles
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Product::with(['creator', 'updater', 'category', 'unitMeasure','subCategories','points','medias']);

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
     * @permission_desc Création des articles
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction(); // Démarre la transaction

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:produits,name',
                'description' => 'nullable|string',
                'category_uuid' => 'nullable|exists:categories,uuid',
                'unit_uuid' => 'nullable|exists:units,uuid',
                'purchase_price' => 'nullable|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'minimum_stock' => 'nullable|integer|min:0',
                'sub_categories' => 'nullable|array',
                'sub_categories.*' => 'exists:sub_categories,uuid',
                'points' => 'nullable|array',
                'points.*.uuid' => 'required|exists:warehouses,uuid',
                'points.*.quantity' => 'nullable|integer|min:0',
                'image_file' => 'nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,svg'
            ], [
                'name.required' => 'Le nom du produit est obligatoire.',
                'name.unique' => 'Ce nom de produit existe déjà.',
                'purchase_price.required' => 'Le prix d\'achat est obligatoire.',
                'sale_price.required' => 'Le prix de vente est obligatoire.',
                'stock_quantity.required' => 'La quantité en stock est obligatoire.',
                'minimum_stock.required' => 'Le stock minimum est obligatoire.',
                'category_uuid.exists' => 'La catégorie sélectionnée n\'existe pas.',
                'unit_uuid.exists' => 'L\'unité de mesure sélectionnée n\'existe pas.',
                'sub_categories.*.exists' => 'Une sous-catégorie sélectionnée est invalide.',
                'points.*.uuid.exists' => 'Un point de dépôt sélectionné est invalide.',
                'points.*.quantity.required' => 'La quantité pour chaque point est obligatoire.',
                'image_file.max' => 'La taille maximale de l\'image du produit doit être de 2Mo',
                'image_fil.mimes' => 'L\'image du produit doit prendre en compte uniquement ce type de fichier: jpg, jpeg et png'
            ]);

            $validated['created_by'] = $auth->id;

            // Création du produit
            $product = Product::create($validated);

            // Association des sous-catégories
            if (!empty($validated['sub_categories'])) {
                $pivotData = [];
                foreach ($validated['sub_categories'] as $sub_uuid) {
                    $pivotData[$sub_uuid] = [
                        'is_active' => true,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ];
                }
                $product->subCategories()->sync($pivotData);
            }

            // Association des points de dépôt
            if (!empty($validated['points'])) {
                $pivotData = [];
                foreach ($validated['points'] as $point) {
                    $pivotData[$point['uuid']] = [
                        'quantity' => $point['quantity'],
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ];
                }
                $product->points()->sync($pivotData);
            }

            // Upload de l'image
            if ($request->hasFile('image_file')) {
                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName(); // pour éviter les doublons
                $path = $file->store('products', 'public');

                $product->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été créé avec succès !",
                'product' => $product->load(['subCategories', 'points'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollback(); // Annule la transaction en cas d'erreur de validation
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les champs saisis.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollback(); // Annule la transaction en cas d'erreur
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
     * @permission_desc Activation/Désactivation des articles
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
     * @permission_desc Afficher les détails des articles
     */
    public function show($uuid)
    {
        try {
            $product = Product::with(['category', 'unitMeasure', 'creator', 'updater','subCategories','points'])
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
     * @permission_desc Modification des articles
     */
    public function update_products(Request $request, $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            $product = Product::findOrFail($uuid);

            // Validation des champs obligatoires
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:produits,name,' . $product->uuid . ',uuid',
                'description' => 'nullable|string',
                'category_uuid' => 'required|exists:categories,uuid',
                'unit_uuid' => 'required|exists:units,uuid',
                'purchase_price' => 'nullable|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'minimum_stock' => 'required|integer|min:0',
                'sub_categories' => 'nullable|array',
                'sub_categories.*' => 'exists:sub_categories,uuid',
                'points' => 'nullable|array',
                'points.*.uuid' => 'required|exists:warehouses,uuid',
                'points.*.quantity' => 'required|integer|min:0',
                'image_file' => 'nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,svg'
            ], [
                'name.required' => 'Le nom du produit est obligatoire.',
                'name.unique' => 'Ce nom de produit existe déjà.',
                'category_uuid.required' => 'La catégorie est obligatoire.',
                'unit_uuid.required' => 'L’unité de mesure est obligatoire.',
                'purchase_price.required' => 'Le prix d’achat est obligatoire.',
                'sale_price.required' => 'Le prix de vente est obligatoire.',
                'stock_quantity.required' => 'La quantité en stock est obligatoire.',
                'minimum_stock.required' => 'Le stock minimum est obligatoire.',
                'points.*.uuid.required' => 'Le point de dépôt est obligatoire.',
                'points.*.quantity.required' => 'La quantité pour chaque point est obligatoire.',
                'image_file.max' => 'La taille maximale de l\'image du produit doit être de 2Mo',
                'image_fil.mimes' => 'L\'image du produit doit prendre en compte uniquement ce type de fichier: jpg, jpeg et png'
            ]);

            $validated['updated_by'] = $auth->id;

            // Mise à jour du produit
            $product->update($validated);

            // Gestion des sous-catégories
            if (isset($validated['sub_categories'])) {
                $pivotData = [];
                foreach ($validated['sub_categories'] as $sub_uuid) {
                    $pivotData[$sub_uuid] = [
                        'updated_by' => $auth->id,
                    ];
                }
                $product->subCategories()->sync($pivotData);
            }

            // Gestion des points de dépôt
            if (isset($validated['points'])) {
                $pivotData = [];
                foreach ($validated['points'] as $point) {
                    $pivotData[$point['uuid']] = [
                        'quantity' => $point['quantity'],
                        'updated_by' => $auth->id,
                    ];
                }
                $product->points()->sync($pivotData);
            }

            // Upload image si présent
            if ($request->hasFile('image_file')) {
                $file = $request->file('image_file');
                $filename = $file->getClientOriginalName();
                $path = $file->store('products', 'public');

                $product->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été mis à jour avec succès !",
                'product' => $product->load(['subCategories', 'points', 'medias'])
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les champs saisis.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Impossible de mettre à jour le produit pour le moment.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ProductController::destroy
     * @permission_desc Suppression des articles
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

        $used = $product->purchaseOrderItems()->exists();

        if ($used) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer ce produit car il est déjà utilisé dans des commandes."
            ], 409);
        }

        // Supprime l'entrepôt
        $product->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "Produit a été supprimé avec succès."
        ]);
        //
    }



}

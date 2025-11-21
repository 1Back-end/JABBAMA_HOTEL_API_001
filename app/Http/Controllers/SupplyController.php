<?php

namespace App\Http\Controllers;

use App\Exports\SuppliersExport;
use App\Exports\SuppliesExport;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supply;
use App\Models\SupplyInvoice;
use App\Models\SupplyItem;
use App\Models\SupplySupplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;


/**
 * @permission_category Gestion des approvisionnements
 */
class SupplyController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission SupplyController::index
     * @permission_desc Afficher la liste des approvisionnements
     */
    public function index(Request $request)
    {
        $auth = auth()->user();

        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Supply::with([
            'items.product',
            'purchaseOrder.items',
            'creator',
            'updater',
            'validator',
            'supplier',
            'warehouse',
            'medias',
            'cancelled'
        ]);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        if ($auth->hasRole('SUPER_ADMIN')) {
            // SUPER ADMIN voit tout → aucun filtre
        } elseif ($auth->hasRole('GESTIONNAIRE_STOCK')) {

            // ✅ GESTIONNAIRE_STOCK voit uniquement ce qu'il a approvisionné
            $query->where('created_by', $auth->id);

        } else {

            //Tous les autres voient ce qu'ils ont approvisionné
            // + ce dont la commande liée est créée par eux
            $query->where(function ($q) use ($auth) {
                $q->where('created_by', $auth->id)
                    ->orWhereHas('purchaseOrder', function ($q2) use ($auth) {
                        $q2->where('created_by', $auth->id);
                    });
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('purchase_order_uuid', 'like', "%{$search}%")
                    ->orWhere('warehouse_uuid', 'like', "%{$search}%")
                    ->orWhere('supplier_uuid', 'like', "%{$search}%")

                    // 🔹 Fournisseur
                    ->orWhereHas('supplier', function ($qs) use ($search) {
                        $qs->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })

                    ->orWhereHas('purchaseOrder', function ($qpr) use ($search) {
                        $qpr->where('reference', 'like', "%{$search}%")
                            ->orWhere('type', 'like', "%{$search}%")
                            ->orWhere('status', 'like', "%{$search}%")
                            ->orWhere('warehouse_from', 'like', "%{$search}%")
                            ->orWhere('warehouse_to', 'like', "%{$search}%")
                            ->orWhere('supplier_uuid', 'like', "%{$search}%");
                    })

                    // 🔹 Entrepôts
                    ->orWhereHas('warehouse', function ($qw) use ($search) {
                        $qw->where('ref', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('stock_type', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })

                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })

                    ->orWhereHas('validator', function ($qv) use ($search) {
                        $qv->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })

                    // 🔹 Produits
                    ->orWhereHas('items.product', function ($qp) use ($search) {
                        $qp->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
            });
        }
        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);

        //
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::store
     * @permission_desc Approvisionner les commandes
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        $purchaseOrder = PurchaseOrder::where('uuid', $request->purchase_order_uuid)->firstOrFail();
        $supplyType = $purchaseOrder->type === 'internal' ? 'internal' : 'external';
        $warehouseUuid = $purchaseOrder->warehouse_uuid;

        $validated = $request->validate([
            'purchase_order_uuid' => 'required|exists:purchase_orders,uuid',
            'notes' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|uuid',
            'items.*.quantity_supplied' => 'required|numeric|min:1',
            'items.*.purchase_price' => $supplyType === 'external' ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.supplier_uuid' => $supplyType === 'external' ? 'required|uuid' : 'nullable|uuid',

            'scanned_documents' => 'nullable|array|min:1',
            'scanned_documents.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',

            'partial_validation_reason' => 'nullable|string',
        ], [

            // purchase order
            'purchase_order_uuid.required' => 'La commande est obligatoire.',
            'purchase_order_uuid.exists'   => 'Commande introuvable.',

            // items
            'items.required' => 'Vous devez ajouter au moins un produit.',
            'items.array'    => 'Format des produits invalide.',
            'items.min'      => 'Vous devez ajouter au moins un produit.',

            'items.*.product_uuid.required' => 'Le produit est obligatoire.',
            'items.*.product_uuid.uuid'     => 'Produit invalide.',

            'items.*.quantity_supplied.required' => 'La quantité est obligatoire.',
            'items.*.quantity_supplied.numeric'  => 'La quantité doit être un nombre.',
            'items.*.quantity_supplied.min'      => 'La quantité doit être au minimum 1.',

            'items.*.purchase_price.required' => 'Le prix total est obligatoire pour une commande externe.',
            'items.*.purchase_price.numeric' => 'Le prix total doit être un nombre.',
            'items.*.purchase_price.min'     => 'Le prix total doit être positif.',

            'items.*.supplier_uuid.required' => 'Le fournisseur est obligatoire pour une commande externe.',
            'items.*.supplier_uuid.uuid'     => 'Fournisseur invalide.',

            // documents
            'scanned_documents.array'  => 'Format des documents invalide.',
            'scanned_documents.min'    => 'Ajoutez au moins un document.',
            'scanned_documents.*.file' => 'Fichier invalide.',
            'scanned_documents.*.mimes' => 'Seuls PDF, JPG, JPEG et PNG sont acceptés.',
            'scanned_documents.*.max'   => 'Chaque fichier ne doit pas dépasser 5 Mo.',

            // partial reason
            'partial_validation_reason.string' => 'Le motif doit être un texte.',
        ]);

        try {
            DB::beginTransaction();
            $partialItems = [];

            // ================================
            //   Vérification quantités
            // ================================
            foreach ($validated['items'] as $index => $item) {
                $poItem = PurchaseOrderItem::where('purchase_order_uuid', $validated['purchase_order_uuid'])
                    ->where('product_uuid', $item['product_uuid'])
                    ->firstOrFail();

                if ($item['quantity_supplied'] > $poItem->quantity) {
                    $qtyOrdered = rtrim(rtrim($poItem->quantity, '0'), '.');

                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'quantity_supplied' => [
                                        "La quantité approvisionnée ({$item['quantity_supplied']}) ne peut pas dépasser la quantité commandée ({$qtyOrdered})."
                                    ]
                                ]
                            ]
                        ]
                    ], 422);
                }

                if ($item['quantity_supplied'] < $poItem->quantity) {
                    $partialItems[] = $poItem->product->name;
                }

            }

            $isFullySupplied = empty($partialItems);
            $totalPrice = $item['purchase_price'] ?? 0;
            $supplierForItem = $item['supplier_uuid'] ?? null;
            $unitPrice = $item['quantity_supplied'] > 0 ? $totalPrice / $item['quantity_supplied'] : 0;

            // ================================
            //       Création Supply
            // ================================
            $supply = Supply::create([
                'purchase_order_uuid' => $validated['purchase_order_uuid'],
                'warehouse_uuid' => $warehouseUuid,
                'supply_date' => now(),
                'notes' => $validated['notes'] ?? null,
                'status' => $isFullySupplied ? 'draft' : 'partially_validated',
                'partially_validated_by' => $isFullySupplied ? null : $auth->id,
                'partial_validation_reason' => $isFullySupplied ? null :
                    ($validated['partial_validation_reason'] ??
                        'Certains produits n’ont pas été approvisionnés complètement : ' . implode(', ', $partialItems)),
                'type' => $supplyType,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'unit_price' => $unitPrice,
            ]);

            $purchaseOrder->update([
                'status' => 'in_discuss',
                'updated_by' => $auth->id,
            ]);

            // ================================
            //     Création SupplyItems
            // ================================
            foreach ($validated['items'] as $item) {
                SupplyItem::create([
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'purchase_price' => $totalPrice,
                    'supplier_uuid'    => $item['supplier_uuid'] ?? null, // 🔹 Fournisseur spécifique à l'item
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $auth->id,
                    'unit_price' => $unitPrice
                ]);
            }

            // ================================
            //   Upload documents
            // ================================
            if ($request->hasFile('scanned_documents')) {
                foreach ($request->file('scanned_documents') as $file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->store('documents_approvisionnements', 'public');

                    $supply->medias()->create([
                        'name' => $filename,
                        'disk' => 'public',
                        'path' => $path,
                        'filename' => $filename,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => $isFullySupplied
                    ? 'Approvisionnement validé avec succès !'
                    : 'Approvisionnement partiellement validé.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur creation approvisionnement : ' . $e->getMessage());
            return response()->json([
                'error' => 'Erreur lors de la création de l’approvisionnement.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::show
     * @permission_desc Afficher les détails d'un approvisionnement
     */
    public function show($uuid)
    {
        try {
            $supply = Supply::with([
                'items.product',
                'items.supplier',       // fournisseurs des items
                'purchaseOrder.items',
                'purchaseOrder.warehouseTo',
                'purchaseOrder.warehouse_from',
                'creator',
                'updater',
                'validator',
                'warehouse',
                'rejector',
                'partially_validated',
                'medias',
                'cancelled',
            ])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => "Détails de l'approvisionnement '{$supply->reference}' récupérés avec succès.",
                'data' => $supply
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Approvisionnement introuvable.',
            ], 404);

        } catch (\Exception $e){
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les détails de la l\' approvisionnement pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::update_supplies
     * @permission_desc Modification des approvisionnements
     */
    public function update_supplies(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $supply = Supply::with('items')->where('uuid', $uuid)->firstOrFail();
        $purchaseOrder = $supply->purchaseOrder;
        $supplyType = $purchaseOrder->type === 'internal' ? 'internal' : 'external';
        $warehouseUuid = $purchaseOrder->warehouse_uuid;

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|uuid',
            'items.*.quantity_supplied' => 'required|numeric|min:1',
            'items.*.purchase_price' => $supplyType === 'external' ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.supplier_uuid' => $supplyType === 'external' ? 'required|uuid' : 'nullable|uuid',
            'scanned_documents' => 'nullable|array|min:1',
            'scanned_documents.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'partial_validation_reason' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();
            $partialItems = [];

            // Vérification quantités
            foreach ($validated['items'] as $index => $item) {
                $poItem = PurchaseOrderItem::where('purchase_order_uuid', $purchaseOrder->uuid)
                    ->where('product_uuid', $item['product_uuid'])
                    ->firstOrFail();

                if ($item['quantity_supplied'] > $poItem->quantity) {
                    $qtyOrdered = rtrim(rtrim($poItem->quantity, '0'), '.');
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'quantity_supplied' => [
                                        "La quantité approvisionnée ({$item['quantity_supplied']}) ne peut pas dépasser la quantité commandée ({$qtyOrdered})."
                                    ]
                                ]
                            ]
                        ]
                    ], 422);
                }

                if ($item['quantity_supplied'] < $poItem->quantity) {
                    $partialItems[] = $poItem->product->name;
                }
            }

            $isFullySupplied = empty($partialItems);

            // Mettre à jour l'approvisionnement
            $totalPrice = $validated['items'][0]['purchase_price'] ?? 0;
            $unitPrice = $validated['items'][0]['quantity_supplied'] > 0 ? $totalPrice / $validated['items'][0]['quantity_supplied'] : 0;

            $supply->update([
                'notes' => $validated['notes'] ?? $supply->notes,
                'status' => $isFullySupplied ? 'draft' : 'partially_validated',
                'partially_validated_by' => $isFullySupplied ? null : $auth->id,
                'partial_validation_reason' => $isFullySupplied ? null :
                    ($validated['partial_validation_reason'] ??
                        'Certains produits n’ont pas été approvisionnés complètement : ' . implode(', ', $partialItems)),
                'unit_price' => $unitPrice,
                'updated_by' => $auth->id,
            ]);

            // Supprimer les anciens items et recréer les nouveaux
            $supply->items()->delete();
            foreach ($validated['items'] as $item) {
                SupplyItem::create([
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'purchase_price' => $item['purchase_price'] ?? 0,
                    'supplier_uuid' => $item['supplier_uuid'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'unit_price' => $item['quantity_supplied'] > 0 ? ($item['purchase_price'] / $item['quantity_supplied']) : 0,
                    'created_by' => $auth->id
                ]);
            }

            // Upload documents
            if ($request->hasFile('scanned_documents')) {
                foreach ($request->file('scanned_documents') as $file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->store('documents_approvisionnements', 'public');

                    $supply->medias()->create([
                        'name' => $filename,
                        'disk' => 'public',
                        'path' => $path,
                        'filename' => $filename,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => $isFullySupplied
                    ? 'Approvisionnement mis à jour avec succès !'
                    : 'Approvisionnement partiellement mis à jour.',
                'data' => $supply->load(['items', 'supplySuppliers.supplier', 'medias'])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur mise à jour approvisionnement : ' . $e->getMessage());
            return response()->json([
                'error' => 'Erreur lors de la mise à jour de l’approvisionnement.',
                'details' => $e->getMessage()
            ], 500);
        }
    }





    /**
     * Display a listing of the resource.
     * @permission SupplyController::destroy
     * @permission_desc Suppression des approvisionnements
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        try {
            $request->validate([
                'password' => 'required|string'
            ]);

            if (!Hash::check($request->password, $auth->password)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Mot de passe incorrect.'
                ], 422);
            }

            // 🔹 Récupérer l'approvisionnement
            $supply = Supply::where('uuid', $uuid)->firstOrFail();

            DB::beginTransaction();

            // 🔹 Remettre à jour le stock des produits
            foreach ($supply->items as $item) {
                Product::where('uuid', $item->product_uuid)
                    ->decrement('stock_quantity', $item->quantity_supplied);
            }

            // 🔹 Supprimer les documents liés
            foreach ($supply->medias as $media) {
                // Supprimer le fichier du disque
                if (\Storage::disk($media->disk)->exists($media->path)) {
                    \Storage::disk($media->disk)->delete($media->path);
                }
                $media->delete();
            }

            // 🔹 Supprimer les SupplyItems
            $supply->items()->delete();

            // 🔹 Supprimer l'approvisionnement
            $supply->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "L’approvisionnement a été supprimé avec succès."
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur suppression approvisionnement : ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer cet approvisionnement.",
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::rejected_supplies
     * @permission_desc Rejet des approvisionnements
     */
    public function rejected_supplies(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Vérifier le mot de passe
        $validated = $request->validate([
            'rejection_reason' => 'required|string', // la raison du rejet
        ], [
            'rejection_reason.required' => "La raison du rejet est obligatoire.",
            'rejection_reason.string' => "La raison doit être une chaîne de caractères.",
        ]);

        try {
            DB::beginTransaction();

            // Récupérer l'approvisionnement avec ses items et produits
            $supply = Supply::with('items.product', 'purchaseOrder')->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;

            if (!$purchaseOrder) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Commande introuvable.'
                ], 404);
            }

            // 🔹 Approvisionnement externe
            if ($supply->type === 'external') {
                $warehouse = Warehouse::where('is_primary', true)->firstOrFail();
                $totalAdded = 0;

                foreach ($supply->items as $item) {
                    $product = $item->product;
                    $product->decrement('stock_quantity', $item->quantity_supplied); // augmenter le stock du produit
                    $totalAdded += $item->quantity_supplied; // garder le total approvisionné
                }

                // Ajouter seulement la quantité approvisionnée au stock total de l'entrepôt
                $warehouse->decrement('total_stock', $totalAdded);

                $supply->update([
                    'status' => 'rejected',
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'rejection_reason' => $validated['rejection_reason'],
                    'rejected_by' => $auth->id,
                ]);

                $purchaseOrder->update([
                    'status' => 'in_discuss',
                    'closed_at' => now(),
                    'updated_by' => $auth->id
                ]);

                DB::commit();

                return response()->json([
                    'message' => "Opération éffectuée avec succès.",
                    'total_added' => $totalAdded,
                    'warehouse_total_stock' => $warehouse->total_stock,
                    'supply' => $supply->load('items.product')
                ]);
            }

            // 🔹 Approvisionnement interne
            if ($supply->type === 'internal') {
                $warehouseFrom = Warehouse::findOrFail($purchaseOrder->warehouse_from);
                $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();

                $totalAdded = 0;

                $totalAdded = 0;

                foreach ($supply->items as $item) {
                    $product = $item->product;

                    if ($product->stock_quantity < $item->quantity_supplied) {
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Quantité insuffisante pour {$product->name}.",
                        ], 422);
                    }

                    // Décrémenter le stock du produit
                    $product->decrement('stock_quantity', $item->quantity_supplied);
                    $totalAdded += $item->quantity_supplied;
                }

                // Décrémenter le total_stock de l'entrepôt source
                $warehouseFrom->decrement('total_stock', $totalAdded);

                // Ajouter exactement la quantité déplacée au stock de destination
                $warehouseTo->increment('total_stock', $totalAdded);

                // Mettre à jour la commande et le supply
                $purchaseOrder->update([
                    'status' => 'in_discuss',
                    'closed_at' => now(),
                    'updated_by' => $auth->id,
                ]);

                $supply->update([
                    'status' => 'rejected',
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'rejection_reason' => $validated['rejection_reason'],
                    'rejected_by' => $auth->id,
                ]);

                DB::commit();

                return response()->json([
                    'message' => "Opération éffectué avec succès.",
                    'warehouse_from_total_stock' => $warehouseFrom->total_stock,
                    'warehouse_to_total_stock' => $warehouseTo->total_stock,
                    'supply' => $supply->load('items.product', 'purchaseOrder')
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => "Type d'approvisionnement inconnu."
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur validation approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => "Une erreur est survenue lors de la validation.",
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::validate_supply
     * @permission_desc Validation complète des approvisionnements
     */
    public function validate_supply(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Vérifier le mot de passe
        $request->validate(['password' => 'required|string']);
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Récupérer l'approvisionnement avec ses items et produits
            $supply = Supply::with('items.product', 'purchaseOrder')->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;

            if (!$purchaseOrder) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Commande introuvable.'
                ], 404);
            }

            // 🔹 Approvisionnement externe
            if ($supply->type === 'external') {
                $warehouse = Warehouse::where('is_primary', true)->firstOrFail();
                $totalAdded = 0;

                foreach ($supply->items as $item) {
                    $product = $item->product;
                    $product->increment('stock_quantity', $item->quantity_supplied); // augmenter le stock du produit
                    $totalAdded += $item->quantity_supplied; // garder le total approvisionné
                }

                // Ajouter seulement la quantité approvisionnée au stock total de l'entrepôt
                $warehouse->increment('total_stock', $totalAdded);

                $supply->update([
                    'status' => 'validated',
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);

                $purchaseOrder->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'updated_by' => $auth->id,
                ]);

                DB::commit();

                return response()->json([
                    'message' => "Approvisionnement externe validé : stock des produits et total de l'entrepôt principal mis à jour.",
                    'total_added' => $totalAdded,
                    'warehouse_total_stock' => $warehouse->total_stock,
                    'supply' => $supply->load('items.product')
                ]);
            }

            // 🔹 Approvisionnement interne
            if ($supply->type === 'internal') {
                $warehouseFrom = Warehouse::findOrFail($purchaseOrder->warehouse_from);
                $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();

                $totalAdded = 0;

                foreach ($supply->items as $item) {
                    $product = $item->product;

                    if ($product->stock_quantity < $item->quantity_supplied) {
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Quantité insuffisante pour {$product->name}.",
                        ], 422);
                    }

                    $product->decrement('stock_quantity', $item->quantity_supplied);
                    $totalAdded += $item->quantity_supplied;
                }

                $warehouseFrom->increment('total_stock', $totalAdded);

                $warehouseTo->decrement('total_stock', $totalAdded);

                // Mettre à jour la commande et le supply
                $purchaseOrder->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'updated_by' => $auth->id,
                ]);

                $supply->update([
                    'status' => 'validated',
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);

            DB::commit();

            return response()->json([
                    'message' => "Approvisionnement interne validé : stock du supply déduit du warehouse source et ajouté à l'entrepôt principal.",
                    'warehouse_from_total_stock' => $warehouseFrom->total_stock,
                    'warehouse_to_total_stock' => $warehouseTo->total_stock,
                    'supply' => $supply->load('items.product', 'purchaseOrder')
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => "Type d'approvisionnement inconnu."
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur validation approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => "Une erreur est survenue lors de la validation.",
                'details' => $e->getMessage()
            ], 500);
        }
    }




    /**
     * Display a listing of the resource.
     * @permission SupplyController::open_supply
     * @permission_desc Ouvrir des approvisionnements
     */
    public function open_supply(Request $request, string $uuid)
    {
        $supply = Supply::findOrFail($uuid);

        // 🔄 Mise à jour du statut
        $supply->update([
            'status' => 'open',
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "L'approvisionnement est maintenant ouvert.",
            'supply' => $supply,
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::cancel_supply
     * @permission_desc Annuler un approvisionnement
     */
    public function cancel_supply(Request $request, string $uuid)
    {
        $supply = Supply::findOrFail($uuid);

        // Validation
        $validated = $request->validate([
            'reason_cancel' => 'required|string|max:255',
        ], [
            'reason_cancel.required' => 'Le motif d\'annulation est obligatoire.',
        ]);

        $supply->update([
            'status'        => 'cancelled',
            'reason_cancel' => $validated['reason_cancel'],
            'cancelled_by'  => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Approvisionnement annulé avec succès.',
            'data' => $supply
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission SupplyController::print_supplies
     * @permission_desc Exporter les détails de l'approvisionnement en PDF
     */
    public function print_supplies(Request $request, string $uuid)
    {
        try {
            // Charger l'approvisionnement avec les relations
            $supply = Supply::with([
                'items.product',
                'purchaseOrder.items',
                'purchaseOrder.warehouseTo',
                'purchaseOrder.warehouse_from',
                'creator',
                'updater',
                'partially_validated',
                'rejector',
                'validator',
                'medias',
                'supplier',
                'warehouse',
                'suppliers.supplier'
            ])->findOrFail($uuid);

            $supply_uuid  = $supply->uuid;
            $fileName     = 'details-approvisionnements-' . $supply_uuid . '-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/details-approvisionnements';
            $filePath     = $folderPath . '/' . $fileName;

            // Créer le dossier si nécessaire (recursive = true)
            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                    throw new \RuntimeException(sprintf('Impossible de créer le répertoire "%s"', $folderPath));
                }
            }

            // Générer le PDF via la fonction save_browser_shot_pdf
            save_browser_shot_pdf(
                view: 'pdfs.details-approvisionnements.details-approvisionnements',
                data: ['supply' => $supply],
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10]
            );

            // Vérifier si le fichier a été généré
            if (!file_exists($filePath)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Le fichier PDF n'a pas été généré."
                ], 500);
            }

            // Lire le PDF et encoder en base64 pour l'envoi
            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'status'   => 'success',
                'message'  => 'Rapport généré avec succès.',
                'data'     => $supply,
                'base64'   => $base64,
                'url'      => asset('storage/details-approvisionnements/' . $fileName),
                'filename' => $fileName,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission SupplyController::reject_supply_by_super_admin
     * @permission_desc Rejetter un approvisionnement par le SUPER_ADMIN
     */
    public function reject_supply_by_super_admin(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Vérifier le mot de passe
        $validated = $request->validate([
            'rejection_reason' => 'required|string', // la raison du rejet
        ], [
            'rejection_reason.required' => "La raison du rejet est obligatoire.",
            'rejection_reason.string' => "La raison doit être une chaîne de caractères.",
        ]);

        try {
            DB::beginTransaction();

            $supply = Supply::with('items.product', 'purchaseOrder')->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;

            if (!$purchaseOrder) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Commande introuvable.'
                ], 404);
            }

            // Déjà traité ?
            if (in_array($supply->status, ['validated', 'rejected'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Impossible : approvisionnement déjà traité."
                ], 422);
            }

            $totalRemoved = 0; // total des quantités à retirer

            // 🔹 EXTERNE => décrémenter partout
            if ($supply->type === 'external') {

                $warehouse = Warehouse::where('is_primary', true)->firstOrFail();

                foreach ($supply->items as $item) {
                    $product = $item->product;

                    // Décrémenter produits
                    if ($product->stock_quantity < $item->quantity_supplied) {
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Quantité insuffisante pour {$product->name} pour annuler."
                        ], 422);
                    }

                    $product->decrement('stock_quantity', $item->quantity_supplied);
                    $totalRemoved += $item->quantity_supplied;
                }

                // Décrémenter le stock total de l'entrepôt principal
                $warehouse->decrement('total_stock', $totalRemoved);
            }

            // 🔹 INTERNE => décrémenter partout
            if ($supply->type === 'internal') {

                $warehouseFrom = Warehouse::findOrFail($purchaseOrder->warehouse_from);
                $warehouseTo   = Warehouse::where('is_primary', true)->firstOrFail();

                foreach ($supply->items as $item) {
                    $product = $item->product;

                    if ($product->stock_quantity < $item->quantity_supplied) {
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Quantité insuffisante pour {$product->name} pour annuler."
                        ], 422);
                    }

                    // Décrémenter produits
                    $product->decrement('stock_quantity', $item->quantity_supplied);

                    $totalRemoved += $item->quantity_supplied;
                }

                // Décrémenter l'entrepôt destination
                $warehouseTo->decrement('total_stock', $totalRemoved);

                // Décrémenter aussi l'entrepôt source
                $warehouseFrom->decrement('total_stock', $totalRemoved);
            }

            // Mettre statut à rejeté
            $supply->update([
                'status' => 'rejected',
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'rejection_reason' => $validated['rejection_reason'],
                'rejected_by' => $auth->id,
            ]);

            $purchaseOrder->update([
                'status' => 'cancelled',
                'closed_at' => now(),
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Approvisionnement rejeté et quantités décrémentées partout.",
                'total_removed' => $totalRemoved,
                'supply' => $supply->load('items.product')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Erreur rejet approvisionnement : " . $e->getMessage());

            return response()->json([
                'error' => 'Erreur lors du rejet.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::export_supply
     * @permission_desc Exporter les approvisionnements au format Excel
     */
    public function export_supply()
    {
        // Remplacer les espaces et ':' par des '_'
        $fileName = 'supply-' . Carbon::now()->format('Y-m-d_H-i-s') . '.xlsx';

        Excel::store(new SuppliesExport(), $fileName, 'exportsupply');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $fileName,
            "url" => Storage::disk('exportsupply')->url($fileName)
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission SupplyController::transfer_supply
     * @permission_desc Transférer les approvisionnements
     */
    public function transfer_supply(Request $request, string $uuid)
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

        $supply = Supply::with('purchaseOrder')->findOrFail($uuid);
        $purchaseOrder = $supply->purchaseOrder;

        if ($supply->created_by !== $auth->id) {
            return response()->json([
                'error' => 'Vous n’êtes pas autorisé à transférer cet approvisionnement.'
            ], 403);
        }

        if (!$purchaseOrder || !$purchaseOrder->created_by) {
            return response()->json([
                'error' => 'Impossible de transférer : commande associée introuvable ou sans créateur.'
            ], 400);
        }

        $supply->update([
            'transferred_at' => now(),
            'transferred_by' => $auth->id,
            'receiver_by' => $purchaseOrder->created_by,
            'status' => 'transferred',
        ]);

        return response()->json([
            'message' => 'Approvisionnement transféré avec succès.',
            'data' => $supply
        ]);
    }










}

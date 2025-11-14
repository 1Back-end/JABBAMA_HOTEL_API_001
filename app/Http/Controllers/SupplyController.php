<?php

namespace App\Http\Controllers;

use App\Exports\SuppliersExport;
use App\Exports\SuppliesExport;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supply;
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
            'suppliers'
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

        // ✅ Validation
        try {
            $validated = $request->validate([
                'purchase_order_uuid' => 'required|exists:purchase_orders,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|uuid',
                'items.*.quantity_supplied' => 'required|numeric|min:1',
                'items.*.purchase_price' => 'nullable|numeric|min:0',
                'items.*.notes' => 'nullable|string',
                'scanned_documents' => 'nullable|array|min:1',
                'scanned_documents.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'supplier_uuid' => 'nullable|array',
                'supplier_uuid.*' => 'uuid',
                'partial_validation_reason' => 'nullable|string'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $purchaseOrder = PurchaseOrder::where('uuid', $validated['purchase_order_uuid'])->firstOrFail();
            $warehouseUuid = $purchaseOrder->warehouse_uuid;

            $supplyType = $purchaseOrder->type === 'internal' ? 'internal' : 'external';

            $partialItems = []; // <-- on stocke les produits partiels

            // ✅ Vérification des produits & quantités
            foreach ($validated['items'] as $index => $item) {
                $poItem = PurchaseOrderItem::where('purchase_order_uuid', $validated['purchase_order_uuid'])
                    ->where('product_uuid', $item['product_uuid'])
                    ->first();

                if (!$poItem) {
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'product_uuid' => ["Le produit sélectionné n'est pas dans la commande d'achat."]
                                ]
                            ]
                        ]
                    ], 422);
                }

                if ((float)$item['quantity_supplied'] > (float)$poItem->quantity) {
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

                // Vérifier si partiel
                if ((float)$item['quantity_supplied'] < (float)$poItem->quantity) {
                    $partialItems[] = $poItem->product->name;
                }
            }

            // ✅ Déterminer statut
            $isFullySupplied = empty($partialItems);

            $supply = Supply::create([
                'purchase_order_uuid' => $validated['purchase_order_uuid'],
                'warehouse_uuid' => $warehouseUuid,
                'supply_date' => now(),
                'notes' => $validated['notes'] ?? null,
                'status' => $isFullySupplied ? 'in_discuss' : 'partially_validated',
                'partially_validated_by' => $isFullySupplied ? null : $auth->id,
                'partial_validation_reason' => $isFullySupplied ? null : ($validated['partial_validation_reason'] ?? 'Certains produits n’ont pas été approvisionnés complètement. '  . implode(', ', $partialItems)),
                'type' => $supplyType,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);

            // Mise à jour statut PO
            $purchaseOrder->update([
                'status' => 'in_discuss',
                'updated_by' => $auth->id,
            ]);

            // ✅ Création des items & mise à jour stock
            foreach ($validated['items'] as $item) {
                $dataItem = [
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $auth->id,
                ];

                if ($supplyType === 'external') {
                    $dataItem['purchase_price'] = $item['purchase_price'] ?? 0;
                }

                SupplyItem::create($dataItem);
            }

            // ✅ Sauvegarde fournisseurs si externe
            if ($supplyType === 'external' && !empty($validated['supplier_uuid'])) {
                foreach ($validated['supplier_uuid'] as $supplierUuid) {
                    SupplySupplier::create([
                        'supply_uuid' => $supply->uuid,
                        'supplier_uuid' => $supplierUuid,
                        'warehouse_uuid' => $warehouseUuid,
                        'notes' => $validated['notes'] ?? null,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]);
                }
            }

            // ✅ Upload documents
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
                    : 'Approvisionnement partiellement validé. Produits non totalement approvisionnés : ' . implode(', ', $partialItems),
                'data' => $supply->load(['items.product', 'warehouse', 'purchaseOrder', 'medias', 'suppliers'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => 'Une erreur est survenue lors de la création de l’approvisionnement.',
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
                'purchaseOrder.items',
                'purchaseOrder.warehouseTo',
                'purchaseOrder.warehouse_from',
                'creator',
                'updater',
                'validator',
                'supplier',
                'warehouse',
                'suppliers.supplier'
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
     * @permission SupplyController::update
     * @permission_desc Modification des approvisionnements
     */
    public function update_supplies(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            $validated = $request->validate([
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|uuid',
                'items.*.quantity_supplied' => 'required|numeric|min:1',
                'items.*.purchase_price' => 'nullable|numeric|min:0',
                'items.*.notes' => 'nullable|string',
                'scanned_documents' => 'nullable|array|min:1',
                'scanned_documents.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'supplier_uuid' => 'nullable|array',
                'supplier_uuid.*' => 'uuid',
                'partial_validation_reason' => 'nullable|string'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $supply = Supply::where('uuid', $uuid)->firstOrFail();
            $purchaseOrder = $supply->purchaseOrder;
            $warehouseUuid = $supply->warehouse_uuid;
            $supplyType = $purchaseOrder->type === 'internal' ? 'internal' : 'external';

            $partialItems = [];

            // ✅ Validation des produits
            foreach ($validated['items'] as $index => $item) {
                $poItem = PurchaseOrderItem::where('purchase_order_uuid', $purchaseOrder->uuid)
                    ->where('product_uuid', $item['product_uuid'])
                    ->first();

                if (!$poItem) {
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'product_uuid' => ["Le produit sélectionné n'est pas dans la commande d'achat."]
                                ]
                            ]
                        ]
                    ], 422);
                }

                if ((float)$item['quantity_supplied'] > (float)$poItem->quantity) {
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'quantity_supplied' => [
                                        "La quantité approvisionnée ({$item['quantity_supplied']}) dépasse la quantité commandée ({$poItem->quantity})."
                                    ]
                                ]
                            ]
                        ]
                    ], 422);
                }

                if ((float)$item['quantity_supplied'] < (float)$poItem->quantity) {
                    $partialItems[] = $poItem->product->name;
                }
            }

            $isFullySupplied = empty($partialItems);

            // ✅ Mise à jour de l’approvisionnement
            $supply->update([
                'notes' => $validated['notes'] ?? null,
                'status' => $isFullySupplied ? 'in_discuss' : 'partially_validated',
                'partially_validated_by' => $isFullySupplied ? null : $auth->id,
                'partial_validation_reason' => $isFullySupplied ? null : ($validated['partial_validation_reason'] ?? 'Certains produits non totalement approvisionnés : ' . implode(', ', $partialItems)),
                'updated_by' => $auth->id,
            ]);

            // ✅ Réinitialiser les anciens items
            foreach ($supply->items as $oldItem) {
                Product::where('uuid', $oldItem->product_uuid)
                    ->decrement('stock_quantity', $oldItem->quantity_supplied);
            }
            $supply->items()->delete();

            // ✅ Enregistrer les nouveaux items
            foreach ($validated['items'] as $item) {
                $dataItem = [
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $auth->id,
                ];

                if ($supplyType === 'external') {
                    $dataItem['purchase_price'] = $item['purchase_price'] ?? 0;
                }

                SupplyItem::create($dataItem);

                Product::where('uuid', $item['product_uuid'])
                    ->increment('stock_quantity', $item['quantity_supplied']);
            }

            // ✅ Mise à jour des fournisseurs
            if ($supplyType === 'external') {
                $supply->suppliers()->delete();

                if (!empty($validated['supplier_uuid'])) {
                    foreach ($validated['supplier_uuid'] as $supplierUuid) {
                        SupplySupplier::create([
                            'supply_uuid' => $supply->uuid,
                            'supplier_uuid' => $supplierUuid,
                            'warehouse_uuid' => $warehouseUuid,
                            'notes' => $validated['notes'] ?? null,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]);
                    }
                }
            }

            // ✅ Upload de nouveaux documents
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
                    : 'Approvisionnement partiellement validé après mise à jour. Produits non totalement approvisionnés : ' . implode(', ', $partialItems),
                'data' => $supply->load(['items.product', 'warehouse', 'purchaseOrder', 'medias', 'suppliers'])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur mise à jour approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => 'Une erreur est survenue lors de la mise à jour de l’approvisionnement.',
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

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $supply = Supply::with(['purchaseOrder', 'items.product'])->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;
            $authId = $auth->id;

            if (!$purchaseOrder) {
                return response()->json(['error' => "Commande d’achat introuvable."], 404);
            }

            $warehouseFrom = Warehouse::find($purchaseOrder->warehouse_from);
            $warehouseTo   = Warehouse::find($purchaseOrder->warehouse_to_uuid);

            // 🔹 Restorer les quantités si approvisionnement déjà validé
            if ($supply->status === 'validated') {
                foreach ($supply->items as $item) {
                    $product = $item->product;

                    // Remettre dans l'entrepôt source
                    $product->increment('stock_quantity', $item->quantity_supplied);

                    // Déduire de l'entrepôt destination si interne
                    if ($purchaseOrder->type === 'internal' && $warehouseTo) {
                        // On considère que la destination a déjà été incrémentée
                        // donc on ne touche pas aux products ici sauf si tu stockes quantité par entrepôt
                    }
                }

                // Mise à jour total_stock des entrepôts
                if ($warehouseFrom) {
                    $warehouseFrom->update(['total_stock' => $warehouseFrom->products()->sum('stock_quantity')]);
                }
                if ($warehouseTo) {
                    $warehouseTo->update(['total_stock' => $warehouseTo->products()->sum('stock_quantity')]);
                }
            }

            // 🔹 Mettre à jour le statut du supply
            $supply->update([
                'status' => 'rejected',
                'rejected_by' => $authId,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'updated_by' => $authId,
            ]);

            $purchaseOrder->update([
                'status' => 'open', // ou 'rejected' selon ta logique
                'updated_by' => $authId,
            ]);

            DB::commit();

            return response()->json([
                'message' => "Approvisionnement rejeté et stocks restaurés.",
                'supply' => $supply->load(['items.product', 'purchaseOrder'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur rejet approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => "Une erreur est survenue lors du rejet.",
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

        $validated = $request->validate([
            'status' => 'required|in:validated',
        ]);

        try {
            DB::beginTransaction();

            // Récupérer l'approvisionnement avec ses relations
            $supply = Supply::with(['purchaseOrder', 'items.product'])->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;
            $authId = $auth->id;

            if (!$purchaseOrder) {
                return response()->json(['error' => "Commande d’achat introuvable."], 404);
            }

            // 🔹 Déterminer les entrepôts
            $warehouseFrom = Warehouse::find($purchaseOrder->warehouse_from);
            $warehouseTo   = Warehouse::find($purchaseOrder->warehouse_to_uuid);

            // Mettre à jour la commande pour enregistrer l'entrepôt source
            if ($warehouseFrom) {
                $purchaseOrder->update([
                    'warehouse_from' => $warehouseFrom->uuid,
                ]);
            }

            // 🔹 Approvisionnement interne
            if ($purchaseOrder->type === 'internal') {

                // Vérification stock dans l'entrepôt source
                foreach ($supply->items as $item) {
                    $product = $item->product;

                    // Le produit doit être dans l'entrepôt source
                    if ($product->warehouse_uuid !== $warehouseFrom?->uuid) {
                        DB::rollBack();
                        return response()->json([
                            'error' => "Le produit {$product->name} n'est pas dans l'entrepôt source."
                        ], 422);
                    }

                    if ($product->stock_quantity < $item->quantity_supplied) {
                        DB::rollBack();
                        return response()->json([
                            'error' => "Quantité insuffisante pour le produit {$product->name}.",
                            'disponible' => $product->stock_quantity,
                            'requise'    => $item->quantity_supplied
                        ], 422);
                    }

                    // Déduction du stock dans l'entrepôt source
                    $product->decrement('stock_quantity', $item->quantity_supplied);

                    // Ajout au stock de l'entrepôt destination si défini
                    if ($warehouseTo) {
                        // Copier le produit dans l'entrepôt destination si nécessaire
                        $destProduct = Product::firstOrCreate(
                            ['uuid' => $product->uuid, 'warehouse_uuid' => $warehouseTo->uuid],
                            [
                                'ref' => $product->ref,
                                'name' => $product->name,
                                'stock_quantity' => 0,
                            ]
                        );
                        $destProduct->increment('stock_quantity', $item->quantity_supplied);
                    }
                }

                // 🔹 Mise à jour total_stock des entrepôts
                if ($warehouseFrom) {
                    $warehouseFrom->update([
                        'total_stock' => Product::where('warehouse_uuid', $warehouseFrom->uuid)->sum('stock_quantity')
                    ]);
                }
                if ($warehouseTo) {
                    $warehouseTo->update([
                        'total_stock' => Product::where('warehouse_uuid', $warehouseTo->uuid)->sum('stock_quantity')
                    ]);
                }
            }
            // 🔹 Approvisionnement externe
            else {
                foreach ($supply->items as $item) {
                    $product = $item->product;
                    $product->increment('stock_quantity', $item->quantity_supplied);

                    // Associer le produit à l'entrepôt si défini
                    if ($warehouseTo && $product->warehouse_uuid !== $warehouseTo->uuid) {
                        $product->update(['warehouse_uuid' => $warehouseTo->uuid]);
                    }
                }

                // Mise à jour total_stock pour l'entrepôt
                if ($warehouseTo) {
                    $warehouseTo->update([
                        'total_stock' => Product::where('warehouse_uuid', $warehouseTo->uuid)->sum('stock_quantity')
                    ]);
                }
            }

            // Mettre à jour les statuts
            $supply->update([
                'status' => 'validated',
                'validated_by' => $authId,
                'updated_by' => $authId,
            ]);

            $purchaseOrder->update([
                'status' => 'closed',
                'closed_at' => now(),
                'updated_by' => $authId,
            ]);

            DB::commit();

            return response()->json([
                'message' => $purchaseOrder->type === 'internal'
                    ? "Approvisionnement interne validé : transfert entre entrepôts et stocks mis à jour."
                    : "Approvisionnement externe validé : stock mis à jour.",
                'supply' => $supply->load(['items.product', 'purchaseOrder'])
            ]);

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
                'validator',
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

        // Seul le SUPER_ADMIN peut rejeter
        if (!$auth->hasRoleName('SUPER_ADMIN')) {
            return response()->json([
                'error' => "Vous n’êtes pas autorisé à rejeter cet approvisionnement."
            ], 403);
        }

        try {
            DB::beginTransaction();

            $supply = Supply::with(['purchaseOrder', 'items.product'])->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;
            $authId = $auth->id;

            if (!$purchaseOrder) {
                return response()->json(['error' => "Commande d’achat introuvable."], 404);
            }

            $warehouseFrom = Warehouse::find($purchaseOrder->warehouse_from);
            $warehouseTo   = Warehouse::find($purchaseOrder->warehouse_to_uuid);

            // 🔹 Restorer les quantités si approvisionnement déjà validé
            if ($supply->status === 'validated') {
                foreach ($supply->items as $item) {
                    $product = $item->product;

                    // Remettre dans l'entrepôt source
                    $product->increment('stock_quantity', $item->quantity_supplied);

                    // Déduire de l'entrepôt destination si interne
                    if ($purchaseOrder->type === 'internal' && $warehouseTo) {
                        // On considère que la destination a déjà été incrémentée
                        // donc on ne touche pas aux products ici sauf si tu stockes quantité par entrepôt
                    }
                }

                // Mise à jour total_stock des entrepôts
                if ($warehouseFrom) {
                    $warehouseFrom->update(['total_stock' => $warehouseFrom->products()->sum('stock_quantity')]);
                }
                if ($warehouseTo) {
                    $warehouseTo->update(['total_stock' => $warehouseTo->products()->sum('stock_quantity')]);
                }
            }

            // 🔹 Mettre à jour le statut du supply
            $supply->update([
                'status' => 'rejected',
                'rejected_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);

            $purchaseOrder->update([
                'status' => 'in_discuss',
                'updated_by' => $auth->id,
            ]);


            DB::commit();

            return response()->json([
                'message' => "Approvisionnement rejeté et stocks restaurés.",
                'supply' => $supply->load(['items.product', 'purchaseOrder'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur rejet approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => "Une erreur est survenue lors du rejet.",
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







}

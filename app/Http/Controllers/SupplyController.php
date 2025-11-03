<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supply;
use App\Models\SupplyItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;


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
            'medias'
        ]);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
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
                'items.*.purchase_price' => 'required|numeric|min:0',
                'items.*.notes' => 'nullable|string',
                'scanned_documents' => 'required|array|min:1',
                'scanned_documents.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ], [
                'purchase_order_uuid.required' => 'Veuillez sélectionner une commande.',
                'purchase_order_uuid.exists' => 'La commande sélectionnée n’existe pas.',
                'scanned_documents.required' => 'Veuillez ajouter au moins un document scanné.',
                'scanned_documents.array' => 'Les documents doivent être envoyés sous forme de liste.',
                'scanned_documents.min' => 'Veuillez ajouter au moins un document de l\'approvisionnement.',
                'scanned_documents.*.file' => 'Chaque document doit être un fichier valide.',
                'scanned_documents.*.mimes' => 'Les documents doivent être au format PDF, JPG, JPEG ou PNG.',
                'scanned_documents.*.max' => 'Chaque document ne doit pas dépasser 5 Mo.',

                'items.required' => 'Veuillez ajouter au moins un produit à approvisionner.',
                'items.*.quantity_supplied.required' => 'Veuillez indiquer la quantité pour chaque produit.',
                'items.*.quantity_supplied.numeric' => 'La quantité doit être un nombre.',
                'items.*.quantity_supplied.min' => 'La quantité doit être au moins 1.',
                'items.*.purchase_price.required' => 'Veuillez indiquer le prix d’achat pour chaque produit.',
                'items.*.purchase_price.numeric' => 'Le prix d’achat doit être un nombre.',
                'items.*.purchase_price.min' => 'Le prix d’achat doit être au moins 0.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // ✅ Récupération de la commande
            $purchaseOrder = PurchaseOrder::where('uuid', $validated['purchase_order_uuid'])->firstOrFail();
            $warehouseUuid = $purchaseOrder->warehouse_uuid;
            $supplierUuid = $purchaseOrder->supplier_uuid;

            // ✅ Vérification des produits & quantités avant création
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
            }

            // ✅ Création du Supply
            $supply = Supply::create([
                'purchase_order_uuid' => $validated['purchase_order_uuid'],
                'warehouse_uuid' => $warehouseUuid,
                'supplier_uuid' => $supplierUuid,
                'supply_date' => now(),
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);

            // ✅ Création des items & mise à jour des produits
            foreach ($validated['items'] as $item) {
                // ➕ Enregistrement du prix d’achat dans supply_items
                SupplyItem::create([
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'purchase_price' => $item['purchase_price'], // ✅ ajouté
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $auth->id,
                ]);

                // 📦 Mise à jour du stock produit
                Product::where('uuid', $item['product_uuid'])
                    ->increment('stock_quantity', $item['quantity_supplied']);

                // 💰 Mise à jour du prix d’achat du produit
//                Product::where('uuid', $item['product_uuid'])
//                    ->update(['purchase_price' => $item['purchase_price']]);
            }

            // ✅ Upload des documents
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
                'message' => 'Approvisionnement créé avec succès !',
                'data' => $supply->load(['items.product', 'warehouse', 'supplier', 'purchaseOrder', 'medias'])
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
                'creator',
                'updater',
                'validator',
                'supplier',
                'warehouse'
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

        // ✅ Validation
        try {
            $validated = $request->validate([
                'purchase_order_uuid' => 'required|exists:purchase_orders,uuid',
                'supply_date' => 'required|date',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|uuid',
                'items.*.quantity_supplied' => 'required|numeric|min:1',
                'items.*.purchase_price' => 'required|numeric|min:0',
                'items.*.notes' => 'nullable|string',
                'scanned_documents' => 'nullable|array',
                'scanned_documents.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ], [
                'purchase_order_uuid.required' => 'Veuillez sélectionner une commande.',
                'purchase_order_uuid.exists' => 'La commande sélectionnée n’existe pas.',
                'supply_date.required' => 'Veuillez saisir la date de l’approvisionnement.',
                'supply_date.date' => 'La date d’approvisionnement doit être une date valide.',
                'items.required' => 'Veuillez ajouter au moins un produit à approvisionner.',
                'items.*.quantity_supplied.required' => 'Veuillez indiquer la quantité pour chaque produit.',
                'items.*.quantity_supplied.numeric' => 'La quantité doit être un nombre.',
                'items.*.quantity_supplied.min' => 'La quantité doit être au moins 1.',
                'items.*.purchase_price.required' => 'Veuillez indiquer le prix d’achat pour chaque produit.',
                'items.*.purchase_price.numeric' => 'Le prix d’achat doit être un nombre.',
                'items.*.purchase_price.min' => 'Le prix d’achat doit être au moins 0.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // ✅ Récupération du Supply existant
            $supply = Supply::where('uuid', $uuid)->firstOrFail();

            $purchaseOrder = PurchaseOrder::where('uuid', $validated['purchase_order_uuid'])->firstOrFail();
            $warehouseUuid = $purchaseOrder->warehouse_uuid;
            $supplierUuid = $purchaseOrder->supplier_uuid;

            // ✅ Vérification des produits & quantités
            foreach ($validated['items'] as $index => $item) {
                $poItem = PurchaseOrderItem::where('purchase_order_uuid', $validated['purchase_order_uuid'])
                    ->where('product_uuid', $item['product_uuid'])
                    ->first();

                if (!$poItem) {
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => ['product_uuid' => ["Le produit sélectionné n'est pas dans la commande d'achat."]]
                            ]
                        ]
                    ], 422);
                }

                if ((float)$item['quantity_supplied'] > (float)$poItem->quantity) {
                    $qtyOrdered = rtrim(rtrim($poItem->quantity, '0'), '.');
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => ['quantity_supplied' => ["La quantité approvisionnée ({$item['quantity_supplied']}) ne peut pas dépasser la quantité commandée ({$qtyOrdered})."]]
                            ]
                        ]
                    ], 422);
                }
            }

            // ✅ Mise à jour du Supply
            $supply->update([
                'purchase_order_uuid' => $validated['purchase_order_uuid'],
                'warehouse_uuid' => $warehouseUuid,
                'supplier_uuid' => $supplierUuid,
                'supply_date' => $validated['supply_date'],
                'notes' => $validated['notes'] ?? null,
                'updated_by' => $auth->id,
            ]);

            // ✅ Suppression des anciens items et restauration du stock
            foreach ($supply->items as $oldItem) {
                Product::where('uuid', $oldItem->product_uuid)
                    ->decrement('stock_quantity', $oldItem->quantity_supplied);
            }
            $supply->items()->delete();

            // ✅ Création des nouveaux items
            foreach ($validated['items'] as $item) {
                SupplyItem::create([
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $auth->id,
                ]);

                Product::where('uuid', $item['product_uuid'])
                    ->increment('stock_quantity', $item['quantity_supplied']);
            }

            // ✅ Upload des nouveaux documents (optionnel)
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
                'message' => 'Approvisionnement mis à jour avec succès !',
                'data' => $supply->load(['items.product', 'warehouse', 'supplier', 'purchaseOrder', 'medias'])
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
     * @permission SupplyController::partially_validated_supplies
     * @permission_desc Validation partielle des approvisionnements
     */
    public function partially_validated_supplies(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'partial_validation_reason' => 'nullable|string|max:1000',
        ]);

        $supply = Supply::findOrFail($uuid);

        // 🔄 Mise à jour des champs
        $supply->update([
            'status' => 'partially_validated',
            'partially_validated_by' => auth()->id(),
            'partial_validation_reason' => $request->partial_validation_reason,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "L'approvisionnement a été partiellement validée avec succès.",
            'supply' => $supply,
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::rejected_supplies
     * @permission_desc Rejet des approvisionnements
     */
    public function rejected_supplies(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ], [
            'rejection_reason.required' => "Le motif du rejet est obligatoire.",
        ]);

        // 🔍 Récupération de l’approvisionnement
        $supply = Supply::findOrFail($uuid);

        // 🔄 Mise à jour des champs
        $supply->update([
            'status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejection_reason' => $request->rejection_reason,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "L’approvisionnement a été rejeté avec succès.",
            'supply' => $supply,
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission SupplyController::validate_supply
     * @permission_desc Validation complète des approvisionnements
     */
    public function validate_supply(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'status' => 'required|in:validated',
        ], [
            'status.required' => "Le statut est obligatoire.",
            'status.in' => "Le statut fourni est invalide.",
        ]);

        $supply = Supply::with('purchaseOrder')->findOrFail($uuid);

        $supply->update([
            'status' => 'validated',
            'validated_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // Clôturer la commande associée
        if ($supply->purchaseOrder) {
            $supply->purchaseOrder->update([
                'status' => 'closed',
                'closed_at' => now(),
                'updated_by' => auth()->id(),
            ]);
        }

        return response()->json([
            'message' => "L’approvisionnement a été validé et la commande a été clôturée avec succès.",
            'supply' => $supply
        ]);
    }




}

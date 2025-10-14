<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::index
     * @permission_desc Afficher la liste des commandes
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = PurchaseOrder::with([
            'items.product', // 🔹 inclure le produit dans les items
            'supplier',
            'warehouseTo.manager',
            'warehouse_from.manager',
            'creator',
            'updater',
            'approver'
        ]);
        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('status')) $query->where('status', $request->status);

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                $request->start_date,
                $request->end_date
            ]);
        }


        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('warehouse_from', 'like', "%{$search}%")
                    ->orWhere('warehouse_to', 'like', "%{$search}%")
                    ->orWhere('supplier_uuid', 'like', "%{$search}%")

                    // 🔹 Recherche dans le fournisseur
                    ->orWhereHas('supplier', function ($qs) use ($search) {
                        $qs->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })

                    // 🔹 Recherche dans les entrepôts
                    ->orWhereHas('warehouseTo', function ($qw) use ($search) {
                        $qw->where('ref', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('stock_type', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })
                    ->orWhereHas('warehouse_from', function ($qf) use ($search) {
                        $qf->where('ref', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('stock_type', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })

                    // 🔹 Recherche dans les créateurs
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })

                    // 🔹 Recherche dans les produits liés à la commande
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
    }




    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::store
     * @permission_desc Création des commandes
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        try {
            // Validation
            $request->validate([
                'type' => 'required|in:external,internal',
                'supplier_uuid' => 'required_if:type,external|nullable|exists:suppliers,uuid',
                'warehouse_from' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'warehouse_to' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'documents.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240', // fichiers acceptés
            ], [
                'supplier_uuid.required_if' => 'Vous devez sélectionner un fournisseur pour une commande externe.',
                'warehouse_from.required_if' => 'Vous devez sélectionner l’entrepôt source pour une commande interne.',
                'warehouse_to.required_if' => 'Vous devez sélectionner l’entrepôt de destination pour une commande interne.',
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
                'items.*.product_uuid.required' => 'Chaque article doit avoir un produit valide.',
                'items.*.quantity.required' => 'Chaque article doit avoir une quantité valide.',
                'documents.*.file' => 'Chaque document doit être un fichier valide.',
            ]);

            // Créer la commande
            $order = PurchaseOrder::create([
                'type' => $request->type,
                'status' => 'draft',
                'supplier_uuid' => $request->type === 'external' ? $request->supplier_uuid : null,
                'warehouse_from' => $request->type === 'internal' ? $request->warehouse_from : null,
                'warehouse_to' => $request->type === 'internal' ? $request->warehouse_to : null,
                'notes' => $request->notes,
                'created_by' => $auth->id,
            ]);

            // Ajouter les items
            foreach ($request->items as $item) {
                $order->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'created_by' => $auth->id,
                ]);
            }

            // Ajouter les documents scannés (plusieurs fichiers possibles)
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->store('orders', 'public');

                    $order->medias()->create([
                        'name' => $filename,
                        'filename' => $filename,
                        'disk' => 'public',
                        'path' => $path,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                        'created_by' => $auth->id,
                    ]);
                }
            }

            // Message personnalisé
            $message = $request->type === 'external'
                ? "Commande externe créée avec succès auprès du fournisseur sélectionné."
                : "Commande interne créée avec succès entre les entrepôts.";

            return response()->json([
                'message' => $message,
                'order' => $order->load(['items', 'medias'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création de la commande.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::show
     * @permission_desc Afficher les détails des commandes
     */
    public function show($uuid)
    {
        try {
            $purchaseOrder = PurchaseOrder::with([
                'items.product',
                'supplier',
                'warehouseTo.manager',
                'warehouse_from.manager',
                'creator',
                'updater',
                'approver'
            ])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => "Détails de la commande '{$purchaseOrder->name}' récupérés avec succès.",
                'data' => $purchaseOrder
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable.',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les détails de la commande pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::update
     * @permission_desc Modification des commandes
     */
    public function update_orders(Request $request, $uuid)
    {
        $auth = auth()->user();
        $purchaseOrder = PurchaseOrder::findOrFail($uuid);

        // Vérifier que la commande peut être modifiée
        if ($purchaseOrder->status !== 'open') {
            return response()->json([
                'message' => 'Cette commande ne peut plus être modifiée car elle est déjà ' . $purchaseOrder->status . '.'
            ], 403);
        }

        try {
            // Validation
            $request->validate([
                'type' => 'sometimes|in:external,internal',
                'supplier_uuid' => 'required_if:type,external|nullable|exists:suppliers,uuid',
                'warehouse_from' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'warehouse_to' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'sometimes|array|min:1',
                'items.*.product_uuid' => 'required_with:items|exists:produits,uuid',
                'items.*.quantity' => 'required_with:items|numeric|min:0.001',
                'documents.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
            ], [
                'supplier_uuid.required_if' => 'Vous devez sélectionner un fournisseur pour une commande externe.',
                'warehouse_from.required_if' => 'Vous devez sélectionner l’entrepôt source pour une commande interne.',
                'warehouse_to.required_if' => 'Vous devez sélectionner l’entrepôt de destination pour une commande interne.',
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
                'items.*.product_uuid.required' => 'Chaque article doit avoir un produit valide.',
                'items.*.quantity.required' => 'Chaque article doit avoir une quantité valide.',
            ]);

            // Mise à jour des informations principales
            $purchaseOrder->update([
                'type' => $request->type ?? $purchaseOrder->type,
                'supplier_uuid' => $request->type === 'external' ? $request->supplier_uuid : null,
                'warehouse_from' => $request->type === 'internal' ? $request->warehouse_from : null,
                'warehouse_to' => $request->type === 'internal' ? $request->warehouse_to : null,
                'notes' => $request->notes ?? $purchaseOrder->notes,
                'status' => 'modified',
                'updated_by' => $auth->id,
            ]);

            // Mise à jour des items si fournis
            if ($request->has('items')) {
                $purchaseOrder->items()->delete();
                foreach ($request->items as $item) {
                    if (!empty($item['product_uuid']) && !empty($item['quantity'])) {
                        $purchaseOrder->items()->create([
                            'product_uuid' => $item['product_uuid'],
                            'quantity' => $item['quantity'],
                            'created_by' => $auth->id,
                        ]);
                    }
                }
            }

            // Ajouter les documents scannés (plusieurs fichiers possibles)
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->store('orders', 'public');

                    $purchaseOrder->medias()->create([
                        'name' => $filename,
                        'filename' => $filename,
                        'disk' => 'public',
                        'path' => $path,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                        'created_by' => $auth->id,
                    ]);
                }
            }

            $message = $purchaseOrder->type === 'external'
                ? "Commande externe modifiée avec succès."
                : "Commande interne modifiée avec succès.";

            return response()->json([
                'message' => $message,
                'order' => $purchaseOrder->load(['items', 'medias'])
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour de la commande.',
                'error' => $e->getMessage()
            ], 500);
        }
    }





    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::destroy
     * @permission_desc Suppression des commandes
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::update_status
     * @permission_desc Changer le statut des commandes
     */
    public function update_status(Request $request, $uuid)
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,open,closed,rejected,modified',
        ]);

        try {
            $order = PurchaseOrder::where('uuid', $uuid)->firstOrFail();

            $oldStatus = $order->status;
            $order->status = $validated['status'];
            $order->updated_by = auth()->id();

            // Si la commande passe en "closed"
            if ($validated['status'] === 'closed') {
                $order->closed_at = now();
            }

            if ($validated['status'] === 'open') {
                $order->closed_at = null;
            }

            $order->save();

            return response()->json([
                'status' => 'success',
                'message' => "Statut mis à jour avec succès de '{$oldStatus}' à '{$order->status}'.",
                'data' => $order
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du statut.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}

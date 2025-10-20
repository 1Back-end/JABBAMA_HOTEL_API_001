<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
            // Validation des données
            $request->validate([
                'type' => 'required|in:external,internal',
                'supplier_uuid' => 'required_if:type,external|nullable|exists:suppliers,uuid',
                'warehouse_from' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'warehouse_to' => 'nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
            ], [
                'supplier_uuid.required_if' => 'Vous devez sélectionner un fournisseur pour une commande externe.',
                'warehouse_from.required_if' => 'Vous devez sélectionner l’entrepôt source pour une commande interne.',
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
            ]);

            $warehouseFromUuid = null;
            $warehouseToUuid = null;
            $supplierUuid = null;

            // Commande externe
            if ($request->type === 'external') {
                if (!$auth->hasRoleName('Econome')) {
                    return response()->json([
                        'message' => 'Seul le responsable de stock (Econome) peut créer une commande externe.'
                    ], 403);
                }

                $supplierUuid = $request->supplier_uuid;
                $warehouseTo = Warehouse::where('manager_id', $auth->id)->first();
                $warehouseToUuid = $warehouseTo ? $warehouseTo->uuid : null;
                $warehouseToName = $warehouseTo ? $warehouseTo->name : null;

            } else { // Commande interne
                $warehouseFrom = Warehouse::where('uuid', $request->warehouse_from)->first();
                if (!$warehouseFrom) {
                    return response()->json(
                        [
                        'message' => 'Entrepôt source introuvable.'
                    ], 404);
                }

                if ($warehouseFrom->manager_id !== $auth->id) {
                    return response()->json([
                        'message' => 'Vous ne pouvez créer une commande interne que depuis votre entrepôt.'
                    ], 403);
                }

                $warehouseFromUuid = $warehouseFrom->uuid;
                $warehouseToUuid = $request->warehouse_to ?? $warehouseFrom->uuid;

                $warehouseToCheck = Warehouse::where('uuid', $warehouseToUuid)->first();
                if (!$warehouseToCheck) {
                    return response()->json(['message' => 'Entrepôt de destination introuvable.'], 404);
                }
            }

            // Création de la commande
            $order = PurchaseOrder::create([
                'type' => $request->type,
                'status' => 'draft',
                'supplier_uuid' => $supplierUuid,
                'warehouse_from' => $warehouseFromUuid,
                'warehouse_to' => $warehouseToUuid,
                'notes' => $request->notes,
                'created_by' => $auth->id,
                'added_by' => $auth->id,
            ]);

            // Ajout des produits pour toutes les commandes
            foreach ($request->items as $item) {
                // Récupérer le produit pour le nom
                $product = Product::find($item['product_uuid']);
                $productName = $product ? $product->name : $item['product_uuid'];

                // Pour les commandes internes, vérifier la disponibilité dans l'entrepôt
                if ($request->type === 'internal') {
                    $available = Product::where('uuid', $item['product_uuid'])
                        ->whereHas('points', function ($q) use ($warehouseFrom) {
                            $q->where('point_uuid', $warehouseFrom->uuid);
                        })->exists();

                    if (!$available) {
                        return response()->json([
                            'message' => "Le produit {$productName} n'est pas disponible dans l'entrepôt."
                        ], 403);
                    }
                }

                $order->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'created_by' => $auth->id,
                    'added_by' => $auth->id,
                ]);
            }

            $message = $request->type === 'external'
                ? "Commande externe créée avec succès (en attente de validation du Manager)."
                : "Commande interne créée avec succès entre les entrepôts.";

            return response()->json([
                'message' => $message,
                'order' => $order->load(['items'])
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
     * @permission PurchaseOrderController::update_orders
     * @permission_desc Modification des commandes
     */
    public function update_orders(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            // Récupérer la commande
            $order = PurchaseOrder::where('uuid', $uuid)->first();
            if (!$order) {
                return response()->json(['message' => 'Commande introuvable.'], 404);
            }

            // Validation des données
            $request->validate([
                'type' => 'required|in:external,internal',
                'supplier_uuid' => 'required_if:type,external|nullable|exists:suppliers,uuid',
                'warehouse_from' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'warehouse_to' => 'nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
            ], [
                'supplier_uuid.required_if' => 'Vous devez sélectionner un fournisseur pour une commande externe.',
                'warehouse_from.required_if' => 'Vous devez sélectionner l’entrepôt source pour une commande interne.',
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
            ]);

            $warehouseFromUuid = null;
            $warehouseToUuid = null;
            $supplierUuid = null;

            // Commande externe
            if ($request->type === 'external') {
                if (!$auth->hasRoleName('Econome')) {
                    return response()->json([
                        'message' => 'Seul le responsable de stock (Econome) peut modifier une commande externe.'
                    ], 403);
                }

                $supplierUuid = $request->supplier_uuid;

                $warehouseTo = Warehouse::where('manager_id', $auth->id)->first();
                $warehouseToUuid = $warehouseTo ? $warehouseTo->uuid : null;

            } else { // Commande interne
                $warehouseFrom = Warehouse::where('uuid', $request->warehouse_from)->first();
                if (!$warehouseFrom) {
                    return response()->json(['message' => 'Entrepôt source introuvable.'], 404);
                }

                if ($warehouseFrom->manager_id !== $auth->id) {
                    return response()->json([
                        'message' => 'Vous ne pouvez modifier une commande interne que depuis votre entrepôt.'
                    ], 403);
                }

                $warehouseFromUuid = $warehouseFrom->uuid;
                $warehouseToUuid = $request->warehouse_to ?? $warehouseFrom->uuid;

                $warehouseToCheck = Warehouse::where('uuid', $warehouseToUuid)->first();
                if (!$warehouseToCheck) {
                    return response()->json(['message' => 'Entrepôt de destination introuvable.'], 404);
                }
            }

            // Mise à jour de la commande
            $order->update([
                'type' => $request->type,
                'supplier_uuid' => $supplierUuid,
                'warehouse_from' => $warehouseFromUuid,
                'warehouse_to' => $warehouseToUuid,
                'notes' => $request->notes,
                'updated_by' => $auth->id,
            ]);

            // Supprimer les anciens articles et recréer les nouveaux (pour tous les types)
            $order->items()->delete();

            foreach ($request->items as $item) {
                // Récupérer le produit pour le nom
                $product = Product::find($item['product_uuid']);
                $productName = $product ? $product->name : $item['product_uuid'];

                // Pour les commandes internes, vérifier la disponibilité dans l'entrepôt
                if ($request->type === 'internal') {
                    $available = Product::where('uuid', $item['product_uuid'])
                        ->whereHas('points', function ($q) use ($warehouseFrom) {
                            $q->where('point_uuid', $warehouseFrom->uuid);
                        })->exists();

                    if (!$available) {
                        return response()->json([
                            'message' => "Le produit {$productName} n'est pas disponible dans l'entrepôt source."
                        ], 403);
                    }
                }

                $order->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'created_by' => $auth->id,
                    'added_by' => $auth->id,
                ]);
            }

            $message = $request->type === 'external'
                ? "Commande externe mise à jour avec succès."
                : "Commande interne mise à jour avec succès.";

            return response()->json([
                'message' => $message,
                'order' => $order->load(['items'])
            ], 200);

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

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::cancel_orders
     * @permission_desc Annuler une commande
     */

    public function cancel_orders(Request $request, string $uuid){
        $validated = $request->validate([
            'status' => 'required|in:cancel',
        ], [
            'status.required' => "Le statut est obligatoire.",
            'status.in' => "Le statut fourni est invalide.",
        ]);
        $order = PurchaseOrder::findOrFail($uuid);
        $order->update([
            'status' => 'cancel',
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "La commande a été annulée avec succès.",
            'order' => $order
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::rejected_orders
     * @permission_desc Rejetter une commande
     */
    public function rejected_orders(Request $request, string $uuid)
    {
        // Validation
        $validated = $request->validate([
            'notes' => 'required|string', // la raison du rejet
        ], [
            'notes.required' => "La raison du rejet est obligatoire.",
            'notes.string' => "La raison doit être une chaîne de caractères.",
        ]);

        // Récupérer la commande
        $order = PurchaseOrder::findOrFail($uuid);

        // Mise à jour de la commande avec statut et notes
        $order->update([
            'status' => 'rejected',
            'notes' => $validated['notes'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "La commande a été rejetée avec succès.",
            'order' => $order
        ]);
    }




    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::send_orders
     * @permission_desc Transferer une commande
     */
    public function send_orders(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Validation du destinataire (utilisateur interne)
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ], [
            'user_id.required' => "L'utilisateur destinataire est obligatoire.",
            'user_id.exists' => "L'utilisateur sélectionné est introuvable.",
        ]);

        // Récupérer la commande et le destinataire
        $order = PurchaseOrder::where('uuid', $uuid)->firstOrFail();
        $recipient = User::findOrFail($validated['user_id']);

        try {
            // Envoi de l'e-mail au destinataire
            Mail::send('emails.order', ['reference' => $order->reference], function ($message) use ($recipient, $order, $auth) {
                $message->to($recipient->email)
                    ->subject('Transmission de la commande ' . $order->reference)
                    ->from($auth->email, $auth->nom_utilisateur ?? 'Système Commandes Jabbama Hotel');
            });

            // ✅ Mise à jour du statut de la commande
            $order->update([
                'status' => 'transferred',
                'updated_by' => $auth->id,
            ]);

            return response()->json([
                'message' => "Commande transférée avec succès à {$recipient->nom_utilisateur} et e-mail envoyé.",
                'order' => $order
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'e-mail.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}

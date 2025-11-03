<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;


/**
 * @permission_category Gestion des commandes
 */
class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::index
     * @permission_desc Afficher la liste des commandes
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = PurchaseOrder::with([
            'items.product',
            'warehouseTo.managers',
            'warehouse_from.managers',
            'creator',
            'updater',
            'approver'
        ]);

        // 🔹 Filtrage par type et statut
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 🔹 Filtrage par période
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        // 🔹 Gestion des accès selon les rôles
        if ($auth->hasRole('GESTIONNAIRE_STOCK')) {
            // 👉 Le gestionnaire de stock voit uniquement les bons transférés par lui
            $query->where('transfered_by', $auth->id);

        } elseif (!$auth->hasRole('SUPER_ADMIN')) {
            // 👉 Tous les autres utilisateurs (sauf Super Admin)
            $query->where('created_by', $auth->id);
        }
        // 👉 Le SUPER_ADMIN voit tout (aucune restriction)

        // 🔹 Recherche globale
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('warehouse_from', 'like', "%{$search}%")
                    ->orWhere('warehouse_to', 'like', "%{$search}%")
                    ->orWhere('supplier_uuid', 'like', "%{$search}%")

                    // 🔹 Entrepôts
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

                    // 🔹 Créateur
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
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

        // 🔹 Pagination
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
                if (! $auth->hasRoleName('ECONOME') && ! $auth->hasRoleName('SUPER_ADMIN')) {
                    return response()->json([
                        'message' => 'Seul le responsable de stock (Econome) peut créer une commande externe.'
                    ], 403);
                }

                $supplierUuid = $request->supplier_uuid;

                // Récupérer le premier entrepôt que le manager gère
                $warehouseTo = $auth->warehouses()->first();
                $warehouseToUuid = $warehouseTo ? $warehouseTo->uuid : null;

            } else { // Commande interne
                $warehouseFrom = Warehouse::where('uuid', $request->warehouse_from)->first();
                if (!$warehouseFrom) {
                    return response()->json(['message' => 'Entrepôt source introuvable.'], 404);
                }

                // Vérifier que l'utilisateur est manager de l'entrepôt
                $isManager = $warehouseFrom->managers()->where('user_id', $auth->id)->exists();
                if (!$isManager) {
                    return response()->json([
                        'message' => 'Vous ne pouvez créer une commande interne que depuis un entrepôt que vous gérez.'
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

            // Ajout des produits
            foreach ($request->items as $item) {
                $product = Product::find($item['product_uuid']);
                $productName = $product ? $product->name : $item['product_uuid'];

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
                'warehouseTo.managers',
                'warehouse_from.managers',
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
            ]);

            // Récupération de la commande
            $order = PurchaseOrder::where('uuid', $uuid)->first();
            if (!$order) {
                return response()->json(['message' => 'Commande introuvable.'], 404);
            }

            // Vérifier si l'utilisateur est autorisé à modifier
            if ($order->created_by !== $auth->id && !$auth->hasRoleName('Admin')) {
                return response()->json([
                    'message' => 'Vous n’êtes pas autorisé à modifier cette commande.'
                ], 403);
            }

            // Variables
            $warehouseFromUuid = null;
            $warehouseToUuid = null;
            $supplierUuid = null;

            // Commande externe
            if ($request->type === 'external') {
                if (!$auth->hasRoleName('ECONOME')) {
                    return response()->json([
                        'message' => 'Seul le responsable de stock (Econome) peut modifier une commande externe.'
                    ], 403);
                }

                $supplierUuid = $request->supplier_uuid;
                $warehouseTo = $auth->warehouses()->first();
                $warehouseToUuid = $warehouseTo ? $warehouseTo->uuid : null;

            } else { // Commande interne
                $warehouseFrom = Warehouse::where('uuid', $request->warehouse_from)->first();
                if (!$warehouseFrom) {
                    return response()->json(['message' => 'Entrepôt source introuvable.'], 404);
                }

                // Vérifier que l'utilisateur gère l'entrepôt
                $isManager = $warehouseFrom->managers()->where('user_id', $auth->id)->exists();
                if (!$isManager) {
                    return response()->json([
                        'message' => 'Vous ne pouvez modifier une commande interne que depuis un entrepôt que vous gérez.'
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
                'status' => 'draft'
            ]);

            // Suppression des anciens items et ajout des nouveaux
            $order->items()->delete();

            foreach ($request->items as $item) {
                $product = Product::find($item['product_uuid']);
                $productName = $product ? $product->name : $item['product_uuid'];

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

            return response()->json([
                'message' => 'Commande mise à jour avec succès.',
                'order' => $order->load('items'),
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
     * @permission PurchaseOrderController::validate_orders
     * @permission_desc Valider une commande
     */
    public function validate_orders(Request $request, string $uuid){
        $validated = $request->validate([
            'status' => 'required|in:validated',
        ], [
            'status.required' => "Le statut est obligatoire.",
            'status.in' => "Le statut fourni est invalide.",
        ]);
        $order = PurchaseOrder::findOrFail($uuid);
        $order->update([
            'status' => 'validated',
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "La commande a été validée avec succès.",
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
            'motif_rejet' => 'required|string', // la raison du rejet
        ], [
            'motif_rejet.required' => "La raison du rejet est obligatoire.",
            'motif_rejet.string' => "La raison doit être une chaîne de caractères.",
        ]);

        // Récupérer la commande
        $order = PurchaseOrder::findOrFail($uuid);

        // Mise à jour de la commande avec statut et notes
        $order->update([
            'status' => 'rejected',
            'motif_rejet' => $validated['motif_rejet'],
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
//            // Envoi de l'e-mail au destinataire
//            Mail::send('emails.order', ['reference' => $order->reference], function ($message) use ($recipient, $order, $auth) {
//                $message->to($recipient->email)
//                    ->subject('Transmission de la commande ' . $order->reference)
//                    ->from($auth->email, $auth->nom_utilisateur ?? 'Système Commandes Jabbama Hotel');
//            });

            // ✅ Mise à jour du statut de la commande
            $order->update([
                'status' => 'open',
                'updated_by' => $auth->id,
                'transfered_by' => $recipient->id,
                'transfered_at' => now(),
            ]);

            return response()->json([
                'message' => "Commande transférée avec succès à {$recipient->nom_utilisateur}.",
                'order' => $order
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'e-mail.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::rejected_orders_by_admin
     * @permission_desc Rejet d’une commande en livraison par le Super Admin
     */
    public function rejected_orders_by_admin(Request $request, string $uuid)
    {
        // Validation
        $validated = $request->validate([
            'motif_rejet' => 'required|string', // la raison du rejet
        ], [
            'motif_rejet.required' => "La raison du rejet est obligatoire.",
            'motif_rejet.string' => "La raison doit être une chaîne de caractères.",
        ]);

        // Récupérer la commande
        $order = PurchaseOrder::findOrFail($uuid);

        // Mise à jour de la commande avec statut et notes
        $order->update([
            'status' => 'rejected',
            'motif_rejet' => $validated['motif_rejet'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "La commande a été rejetée avec succès.",
            'order' => $order
        ]);
    }

}

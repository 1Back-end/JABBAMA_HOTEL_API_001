<?php

namespace App\Http\Controllers;

use App\DTO\ClientFilterData;
use App\DTO\PurchaseOrderFilterData;
use App\Exports\PurchaseOrdersExport;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

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

        $start_date = Carbon::parse($request->input('start_date'))->startOfDay();
        $end_date = Carbon::parse($request->input('end_date'))->endOfDay();


        $query = PurchaseOrder::with([
            'items.product',
            'warehouseTo.managers',
            'warehouseFrom.managers',
            'creator',
            'updater',
            'approver',
            'children',
            'parent'
        ]);

        // 🔹 Filtrage par type et statut
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('warehouse_from')) {
            $query->where('warehouse_from', $request->warehouse_from);
        }

        // 🔹 Filtrage par période
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$start_date, $end_date]);
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
                    ->orWhereHas('warehouseFrom', function ($qf) use ($search) {
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
            // 🔹 Validation des données
            $request->validate([
                'type' => 'required|in:external,internal',
                'supplier_uuid' => 'nullable|exists:suppliers,uuid',
                'warehouse_from' => 'nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
            ], [
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
            ]);

            // 🔹 Préparation des variables
            $supplierUuid = $request->type === 'external' ? $request->supplier_uuid : null;
            $warehouseFromUuid = null;
            $warehouseToUuid = null;

            if ($request->type === 'internal') {
                // Entrepôt source
                $warehouseFrom = Warehouse::find($request->warehouse_from);
                if (!$warehouseFrom) {
                    return response()->json(['message' => 'Entrepôt source introuvable.'], 404);
                }

                // Vérifier que l'utilisateur est manager
                $isManager = $warehouseFrom->managers()->where('user_id', $auth->id)->exists();
                if (!$isManager) {
                    return response()->json([
                        'message' => 'Vous ne pouvez créer une commande interne que depuis un entrepôt que vous gérez.'
                    ], 403);
                }

                // Entrepôt destination = entrepôt principal
                $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();

                $warehouseFromUuid = $warehouseFrom->uuid;
                $warehouseToUuid = $warehouseTo->uuid;
            }

            // 🔹 Création de la commande
            $order = PurchaseOrder::create([
                'type' => $request->type,
                'status' => 'draft',
                'supplier_uuid' => $supplierUuid,
                'warehouse_from' => $warehouseFromUuid,
                'warehouse_to' => $warehouseToUuid,
                'notes' => $request->notes,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'added_by' => $auth->id,
            ]);

            // 🔹 Ajout des produits
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
                ? "Commande externe créée avec succès."
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
                'warehouseFrom.managers',
                'warehouseTo.natures',
                'warehouseFrom.natures',
                'creator',
                'updater',
                'approver',
                'children',
                'parent'
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
                'supplier_uuid' => 'nullable|exists:suppliers,uuid',
                'warehouse_from' => 'required_if:type,internal|nullable|exists:warehouses,uuid',
                'warehouse_to' => 'nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
            ], [
                'warehouse_from.required_if' => 'Vous devez sélectionner l’entrepôt source pour une commande interne.',
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
            ]);

            $supplierUuid = $request->type === 'external' ? $request->supplier_uuid : null;
            $warehouseFromUuid = null;
            $warehouseToUuid = null;

            // Commande externe
            if ($request->type === 'internal') {
                // Entrepôt source
                $warehouseFrom = Warehouse::find($request->warehouse_from);
                if (!$warehouseFrom) {
                    return response()->json(['message' => 'Entrepôt source introuvable.'], 404);
                }

                // Vérifier que l'utilisateur est manager
                $isManager = $warehouseFrom->managers()->where('user_id', $auth->id)->exists();
                if (!$isManager) {
                    return response()->json([
                        'message' => 'Vous ne pouvez créer une commande interne que depuis un entrepôt que vous gérez.'
                    ], 403);
                }

                // Entrepôt destination = entrepôt principal
                $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();

                $warehouseFromUuid = $warehouseFrom->uuid;
                $warehouseToUuid = $warehouseTo->uuid;
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
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        try {
            // Vérifier le mot de passe
            $request->validate([
                'password' => 'required|string'
            ]);

            if (!Hash::check($request->password, $auth->password)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Mot de passe incorrect.'
                ], 422);
            }

            DB::beginTransaction();

            // Récupérer la commande avec ses items
            $order = PurchaseOrder::with('items')->where('uuid', $uuid)->firstOrFail();

            // Vérifier si la commande a un approvisionnement
            $hasSupply = \App\Models\Supply::where('purchase_order_uuid', $order->uuid)->exists();
            if ($hasSupply) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Impossible de supprimer cette commande car elle a déjà un approvisionnement."
                ], 400);
            }

            // Supprimer tous les items associés
            $order->items()->delete();

            // Supprimer la commande
            $order->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "La commande et ses items ont été supprimés avec succès."
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur suppression commande : ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer la commande.",
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::cancel_orders
     * @permission_desc Annuler une commande
     */
    public function cancel_orders(Request $request, string $uuid){
        $auth = auth()->user();
        $request->validate([
            'password' => 'required|string'
        ]);

        // Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }
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
     * @permission PurchaseOrderController::cancel_orders_by_admin
     * @permission_desc Annuler une commande par le SUPER ADMIN
     */
    public function cancel_orders_by_admin(Request $request, string $uuid){
        $auth = auth()->user();
        $request->validate([
            'password' => 'required|string'
        ]);

        // Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }
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
        $auth = auth()->user();
        $request->validate([
            'password' => 'required|string'
        ]);

        // Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $order = PurchaseOrder::findOrFail($uuid);
        $order->update([
            'status' => 'validated',
            'updated_by' => auth()->id(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'closed_at' => now()
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
            'closed_at' => now()
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
     * @permission_desc Rejetter une commande par le SUPER ADMIN
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
            'closed_at' => now()
        ]);

        return response()->json([
            'message' => "La commande a été rejetée avec succès.",
            'order' => $order
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::HaveRoleToSeeInternalOrder
     * @permission_desc Afficher l'option des commandes internes
     */
    public function HaveRoleToSeeInternalOrder(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $internal = $request->get("internal");
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::HaveRoleToSeeExternalOrder
     * @permission_desc Afficher l'option des commandes externes
     */
    public function HaveRoleToSeeExternalOrder(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $external = $request->get("external");
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::export_orders
     * @permission_desc Exporter les commandes au format Excel
     */
    public function export_orders(Request $request)
    {
        $filter = PurchaseOrderFilterData::fromRequestPurchaseOrder($request);
        $filename = 'LISTE-DES-COMMANDES-' . now()->format('dmY') . '.xlsx';

        $ordersQuery = purchase_order_filter($filter, false);

        Excel::store(new PurchaseOrdersExport($ordersQuery), $filename, 'exportorders');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $filename,
            "url" => Storage::disk('exportorders')->url($filename)
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::show_parents_orders
     * @permission_desc Afficher les détails d’une commande parent
     */
    public function show_parents_orders($uuid)
    {
        try {

            $purchaseOrder = PurchaseOrder::with([
                'items' => function ($q) {
                    $q->where('quantity_remaining', '>', 0)
                        ->with('product');
                },
                'supplier',
                'warehouseTo.managers',
                'warehouseFrom.managers',
                'warehouseTo.natures',
                'warehouseFrom.natures',
                'creator',
                'updater',
                'approver',
                'children',
                'parent'
            ])
                ->where('uuid', $uuid)
                ->where('status', 'partially_closed')
                ->whereHas('items', function ($q) {
                    $q->where('quantity_remaining', '>', 0);
                })
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => "Détails de la commande récupérés avec succès.",
                'data' => $purchaseOrder
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable, non partiellement clôturée ou sans quantité restante.',
            ], 404);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les détails de la commande.',
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::create_parents_orders
     * @permission_desc Créer une commande enfant
     */
    public function create_parents_orders(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Récupération de la commande parent
        $parentOrder = PurchaseOrder::where('uuid', $uuid)->firstOrFail();

        // 🔹 S'assurer que la commande parent est marquée comme parent
        if (!$parentOrder->is_parent) {
            $parentOrder->update([
                'is_parent' => true
            ]);
        }

        try {
            DB::beginTransaction();

            // 🔹 Validation des données
            $request->validate([
                'type' => 'required|in:external,internal',
                'supplier_uuid' => 'nullable|exists:suppliers,uuid',
                'warehouse_from' => 'nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
            ], [
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
            ]);

            // 🔹 Préparer entrepôts et fournisseur
            $supplierUuid = $request->type === 'external' ? $request->supplier_uuid : null;
            $warehouseFromUuid = null;
            $warehouseToUuid = null;

            if ($request->type === 'internal') {
                $warehouseFrom = Warehouse::findOrFail($request->warehouse_from);

                // Vérifier que l'utilisateur est manager de l'entrepôt
                $isManager = $warehouseFrom->managers()->where('user_id', $auth->id)->exists();
                if (!$isManager) {
                    return response()->json([
                        'message' => 'Vous ne pouvez créer une commande interne que depuis un entrepôt que vous gérez.'
                    ], 403);
                }

                $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();
                $warehouseFromUuid = $warehouseFrom->uuid;
                $warehouseToUuid = $warehouseTo->uuid;
            }

            // 🔹 Création du bon de commande enfant
            $childOrder = PurchaseOrder::create([
                'parent_uuid' => $parentOrder->uuid,
                'type' => $request->type,
                'status' => 'draft',
                'supplier_uuid' => $supplierUuid,
                'warehouse_from' => $warehouseFromUuid,
                'warehouse_to' => $warehouseToUuid,
                'notes' => $request->notes,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'added_by' => $auth->id,
                'is_parent' => false,
            ]);

            // 🔹 Ajout des produits
            foreach ($request->items as $index => $item) {
                $product = Product::find($item['product_uuid']);
                $productName = $product ? $product->name : $item['product_uuid'];

                // Vérification disponibilité pour commandes internes
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

                // Vérifier que le produit existe dans la commande parent
                $parentItem = PurchaseOrderItem::where('purchase_order_uuid', $parentOrder->uuid)
                    ->where('product_uuid', $item['product_uuid'])
                    ->first();

                if (!$parentItem) {
                    return response()->json([
                        'message' => "Le produit {$productName} n'existe pas dans la commande parent."
                    ], 422);
                }

                // Vérifier la quantité restante
                if ($item['quantity'] > $parentItem->quantity_remaining) {
                    return response()->json([
                        'message' => "La quantité demandée pour le produit {$productName} ({$item['quantity']}) dépasse la quantité restante dans la commande parent ({$parentItem->quantity_remaining})."
                    ], 422);
                }

                $rest = $parentItem->quantity_remaining - $item['quantity'];

                $parentItem->update([
                    'quantity_remaining' => $rest,
                ]);

                // Création de l'article enfant
                $childOrder->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'created_by' => $auth->id,
                    'added_by' => $auth->id,
                ]);
            }

            DB::commit();

            $message = $request->type === 'external'
                ? "Commande externe enfant créée avec succès."
                : "Commande interne enfant créée avec succès entre les entrepôts.";

            return response()->json([
                'message' => $message,
                'order' => $childOrder->load(['items', 'parent'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création de la commande.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::update_parents_orders
     * @permission_desc Modifier une commande enfant
     */
    public function update_parents_orders(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Récupérer la commande enfant à mettre à jour
        $childOrder = PurchaseOrder::where('uuid', $uuid)->firstOrFail();

        // 🔹 Récupérer la commande parent si elle existe
        $parentOrder = $childOrder->parent_uuid ? PurchaseOrder::where('uuid', $childOrder->parent_uuid)->first() : null;

        try {
            DB::beginTransaction();

            // 🔹 Validation des données
            $request->validate([
                'type' => 'required|in:external,internal',
                'supplier_uuid' => 'nullable|exists:suppliers,uuid',
                'warehouse_from' => 'nullable|exists:warehouses,uuid',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|exists:produits,uuid',
                'items.*.quantity' => 'required|numeric|min:0.001',
            ], [
                'items.required' => 'Vous devez ajouter au moins un produit à la commande.',
            ]);

            // 🔹 Préparer entrepôts et fournisseur
            $supplierUuid = $request->type === 'external' ? $request->supplier_uuid : null;
            $warehouseFromUuid = null;
            $warehouseToUuid = null;

            if ($request->type === 'internal') {
                $warehouseFrom = Warehouse::findOrFail($request->warehouse_from);

                $isManager = $warehouseFrom->managers()->where('user_id', $auth->id)->exists();
                if (!$isManager) {
                    return response()->json([
                        'message' => 'Vous ne pouvez mettre à jour une commande interne que depuis un entrepôt que vous gérez.'
                    ], 403);
                }

                $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();
                $warehouseFromUuid = $warehouseFrom->uuid;
                $warehouseToUuid = $warehouseTo->uuid;
            }

            // 🔹 Remettre les quantités de l'ancien enfant dans le parent
            if ($parentOrder) {
                foreach ($childOrder->items as $oldItem) {
                    $parentItem = PurchaseOrderItem::where('purchase_order_uuid', $parentOrder->uuid)
                        ->where('product_uuid', $oldItem->product_uuid)
                        ->first();

                    if ($parentItem) {
                        $parentItem->update([
                            'quantity_remaining' => $parentItem->quantity_remaining + $oldItem->quantity
                        ]);
                    }
                }
            }

            // 🔹 Supprimer les anciens articles
            $childOrder->items()->delete();

            // 🔹 Mise à jour de la commande enfant
            $childOrder->update([
                'type' => $request->type,
                'supplier_uuid' => $supplierUuid,
                'warehouse_from' => $warehouseFromUuid,
                'warehouse_to' => $warehouseToUuid,
                'notes' => $request->notes,
                'updated_by' => $auth->id,
                'status' => 'draft',
            ]);

            // 🔹 Ajouter les nouveaux items et mettre à jour le parent
            foreach ($request->items as $item) {
                $product = Product::find($item['product_uuid']);
                $productName = $product ? $product->name : $item['product_uuid'];

                // Vérification disponibilité pour commandes internes
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

                if ($parentOrder) {
                    $parentItem = PurchaseOrderItem::where('purchase_order_uuid', $parentOrder->uuid)
                        ->where('product_uuid', $item['product_uuid'])
                        ->first();

                    if (!$parentItem) {
                        return response()->json([
                            'message' => "Le produit {$productName} n'existe pas dans la commande parent."
                        ], 422);
                    }

                    if ($item['quantity'] > $parentItem->quantity_remaining) {
                        return response()->json([
                            'message' => "La quantité demandée pour le produit {$productName} ({$item['quantity']}) dépasse la quantité restante dans la commande parent ({$parentItem->quantity_remaining})."
                        ], 422);
                    }

                }

                $childOrder->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'created_by' => $auth->id,
                    'added_by' => $auth->id,
                ]);
            }

            DB::commit();

            $message = $request->type === 'external'
                ? "Commande externe enfant mise à jour avec succès."
                : "Commande interne enfant mise à jour avec succès.";

            return response()->json([
                'message' => $message,
                'order' => $childOrder->load(['items', 'parent'])
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour de la commande.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission PurchaseOrderController::print_orders
     * @permission_desc Imprimer une commande au format PDF
     */
    public function print_orders(Request $request, string $uuid)
    {
        $auth = auth()->user();
        try {
            DB::beginTransaction();

            $order = PurchaseOrder::with([
                'items.product',
                'creator',
                'updater',
                'approver',
                'children',
                'parent',
                'supplier',
                'warehouseTo',
                'warehouseFrom',
            ])->findOrFail($uuid);

            $fileName   = strtoupper('COMMANDE-N°-' . strtoupper($order->reference) . '-'. '.pdf');
            $folderPath = 'storage/details-orders/' . $order->uuid;
            $filePath   = $folderPath . '/' . $fileName;

            // Créer le dossier si nécessaire
            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                    throw new \RuntimeException("Impossible de créer le répertoire : {$folderPath}");
                }
            }

            $data = ['order' => $order];

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.details-orders.details-orders',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
            );

            DB::commit();

            if (!file_exists($filePath)) {
                return response()->json(['message' => "Le fichier PDF n'a pas été généré."], 500);
            }

            // Chercher si le document existe déjà
            $pdf = PdfDocument::where('order_uuid', $order->uuid)
                ->where('name', 'DETAILS-ORDERS')
                ->first();

            // S'il existe → on met à jour le fichier
            if ($pdf) {
                $pdf->update([
                    'path'       => $filePath,
                    'filename'   => $fileName,
                    'updated_by' => $auth->id,
                ]);
            }
            // Sinon → on crée un nouvel enregistrement
            else {
                $pdf = PdfDocument::create([
                    'name'       => 'DETAILS-ORDERS',
                    'order_uuid' => $order->uuid,
                    'disk'       => 'public',
                    'path'       => $filePath,
                    'filename'   => $fileName,
                    'mimetype'   => 'application/pdf',
                    'extension'  => 'pdf',
                    'created_by' => auth()->id(),
                ]);
            }


            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
                'document' => $pdf,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error("Erreur génération PDF commande : " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Erreur lors de la génération du fichier PDF.",
                'details' => $e->getMessage()
            ], 500);
        }
    }









}

<?php

namespace App\Http\Controllers;

use App\DTO\PurchaseOrderFilterData;
use App\DTO\SupplyFilterData;
use App\Enums\PurchaseOrdersStatus;
use App\Enums\PurchaseOrdersType;
use App\Enums\SupplyStatus;
use App\Exports\PurchaseOrdersExport;
use App\Exports\SuppliersExport;
use App\Exports\SuppliesExport;
use App\Models\DecisionalNotification;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderNotification;
use App\Models\StatisticsPurchaseOrder;
use App\Models\StatisticSupply;
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
 * @permission_module Gestion des stocks
 */
class SupplyController extends Controller
{

    public function SupplyStatus()
    {
        return response()->json([
            'status' => 'success',
            'data'   => SupplyStatus::toArray(),
        ]);
    }



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
        $start_date = Carbon::parse($request->input('start_date'))->startOfDay();
        $end_date = Carbon::parse($request->input('end_date'))->endOfDay();

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
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('purchase_order_uuid')) {
            $query->where('purchase_order_uuid', $request->purchase_order_uuid);
        }

        if ($request->filled('warehouse_uuid')) {
            $query->where('warehouse_uuid', $request->warehouse_uuid);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start_date = Carbon::parse($request->input('start_date'))->startOfDay();
            $end_date = Carbon::parse($request->input('end_date'))->endOfDay();

            $query->whereBetween('created_at', [$start_date, $end_date]);
        } else {
            $query->whereDate('created_at', Carbon::today());
        }

        if (!$auth->hasRole('SUPER_ADMIN')) {

            $roleIds = $auth->roles->pluck('id');

            $query->where(function ($q) use ($auth, $roleIds) {

                // 🔹 Utilisateurs avec la permission view_role_related_data
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('purchaseOrder.creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
                // 🔹 Utilisateurs avec la permission view_transferred_orders
                if ($auth->can('view_transferred_supplies')) {
                    $q->orWhereHas('purchaseOrder', fn($po) => $po->whereNotNull('transfered_by'));
                }
                // 🔹 Utilisateurs sans aucune de ces permissions : seulement leurs propres commandes
                if (!$auth->can('view_role_related_data') && !$auth->can('view_transferred_supplies')) {
                    $q->orWhereHas('purchaseOrder', fn($po) => $po->where('created_by', $auth->id));
                }

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

    private function sendNotification(
        PurchaseOrder $order,
        string $status,
        string $message,
        ?int $userId = null,
        ?string $target = null
    ): void {
        $auth = auth()->user();

        PurchaseOrderNotification::create([
            'purchase_order_uuid' => $order->uuid,
            'status' => $status,
            'message' => $message,
            'target' => $target,
            'user_id' => $userId,
            'is_read' => false,
            'created_by' => $auth ? $auth->id : null,
            'updated_by' => $auth ? $auth->id : null,
            'is_for_approvisionnements' => true
        ]);

        DecisionalNotification::create([
            'purchase_order_uuid' => $order->uuid,
            'status' => $status,
            'message' => $message,
            'target' => $target,
            'user_id' => $userId,
            'is_read' => false,
            'created_by' => $auth ? $auth->id : null,
            'updated_by' => $auth ? $auth->id : null,
            'is_for_approvisionnements' => true
        ]);

        // TODO (Optionnel) : Si vous utilisez Laravel Reverb / Pusher pour le temps réel,
        // vous pouvez déclencher un BroadcastEvent ici pour faire sonner le front.
    }

    private function createPurchaseOrderStatistic($order, $item, $auth, array $options = [])
    {
        $productUuid = is_array($item) ? ($item['product_uuid'] ?? null) : ($item->product_uuid ?? null);
        $quantity = is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0);
        $status = $options['status'] ?? $order->status ?? 'draft';

        return StatisticsPurchaseOrder::updateOrCreate(
            [
                'purchase_order_uuid' => $order->uuid ?? null,
                'product_uuid'        => $productUuid,
                'status'              => $status,
            ],
            [
                'quantity'            => $quantity,
                'transfered_by'       => $options['transfered_by'] ?? null,
                'transfered_at'       => $options['transfered_at'] ?? null,
                'validated_by'        => $options['validated_by'] ?? null,
                'validated_at'        => $options['validated_at'] ?? null,
                'rejected_by'         => $options['rejected_by'] ?? null,
                'rejected_at'         => $options['rejected_at'] ?? null,
                'received_by'         => $options['received_by'] ?? null,
                'received_at'         => $options['received_at'] ?? null,
                'approved_by'         => $options['approved_by'] ?? null,
                'approved_at'         => $options['approved_at'] ?? null,
                'cancelled_by'        => $options['cancelled_by'] ?? null,
                'cancelled_at'        => $options['cancelled_at'] ?? null,
                'updated_by'          => $auth->id,
                'created_by'          => $auth->id,
            ]
        );
    }

    private function createSupplyStatistic($order, $item, $auth, array $options = [])
    {
        $productUuid = is_array($item) ? ($item['product_uuid'] ?? null) : ($item->product_uuid ?? null);
        $quantity = is_array($item)
            ? ($item['quantity_supplied'] ?? $item['quantity'] ?? 0)
            : ($item->quantity_supplied ?? $item->quantity ?? 0);

        $supplierUuid = is_array($item) ? ($item['supplier_uuid'] ?? null) : ($item->supplier_uuid ?? null);
        $status = $options['status'] ?? 'draft';

        $supplyUuid = $options['supply_uuid'] ?? (is_array($item) ? ($item['supply_uuid'] ?? null) : ($item->supply_uuid ?? null));

        return StatisticSupply::updateOrCreate(
            [
                'purchase_order_uuid' => $order->uuid ?? null,
                'supply_uuid'         => $supplyUuid,
                'product_uuid'        => $productUuid,
                'status'              => $status,
            ],
            [
                'quantity'            => $quantity,
                'supplier_uuid'       => $options['supplier_uuid'] ?? $supplierUuid,
                'transfered_by'       => $options['transfered_by'] ?? null,
                'transfered_at'       => $options['transfered_at'] ?? null,
                'validated_by'        => $options['validated_by'] ?? null,
                'validated_at'        => $options['validated_at'] ?? null,
                'rejected_by'         => $options['rejected_by'] ?? null,
                'rejected_at'         => $options['rejected_at'] ?? null,
                'received_by'         => $options['received_by'] ?? null,
                'received_at'         => $options['received_at'] ?? null,
                'approved_by'         => $options['approved_by'] ?? null,
                'approved_at'         => $options['approved_at'] ?? null,
                'cancelled_by'        => $options['cancelled_by'] ?? null,
                'cancelled_at'        => $options['cancelled_at'] ?? null,
                'updated_by'          => $auth->id,
                'created_by'          => $auth->id,
            ]
        );
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

        if ($supplyType === 'internal') {
            $warehouseUuidSave = $purchaseOrder->warehouse_from;
        } else {
            $warehousePrimary = Warehouse::where('is_primary', true)->firstOrFail();
            $warehouseUuidSave = $warehousePrimary->uuid;
        }

        $validated = $request->validate([
            'purchase_order_uuid' => 'required|exists:purchase_orders,uuid',
            'notes' => 'nullable|string',


            'items' => 'required|array|min:1',
            'items.*.sell_price' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
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

                $warehousePrimary = Warehouse::where('is_primary', true)->firstOrFail();
                $warehouseUuid = $warehousePrimary->uuid;

                $pointStock = (float) (ProductPoint::where('produit_uuid', $item['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0);

                $quantitySupplied = (float) $item['quantity_supplied'];

                if ($purchaseOrder->type === 'internal') {
                    if ($quantitySupplied > $pointStock) {
                        return response()->json([
                            'errors' => [
                                'items' => [
                                    $index => [
                                        'quantity_supplied' => [
                                            "La quantité approvisionnée ({$item['quantity_supplied']}) ne peut pas dépasser le stock disponible."
                                        ]
                                    ]
                                ]
                            ]
                        ], 422);
                    }
                }
                if ($item['quantity_supplied'] < $poItem->quantity) {
                    $partialItems[] = $poItem->product->name;
                }

            }

            $isFullySupplied = empty($partialItems);
            $totalPrice = $item['purchase_price'] ?? 0;
            $supplierForItem = $item['supplier_uuid'] ?? null;
            $unitPrice = $item['quantity_supplied'] > 0 ? $totalPrice / $item['quantity_supplied'] : 0;

            $supply = Supply::create([
                'purchase_order_uuid' => $validated['purchase_order_uuid'],
                'warehouse_uuid' => $warehouseUuidSave,
                'supply_date' => now(),
                'notes' => $validated['notes'] ?? null,
                'status' => $isFullySupplied ? 'draft' : 'draft',
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


            foreach ($validated['items'] as $item) {
                SupplyItem::create([
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'purchase_price' => $item['purchase_price'],
                    'supplier_uuid'    => $item['supplier_uuid'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $auth->id,
                    'unit_price' => $item['unit_price'],
                    'sell_price' => $item['sell_price']
                ]);
                $poItemRecord = PurchaseOrderItem::where('purchase_order_uuid', $purchaseOrder->uuid)
                    ->where('product_uuid', $item['product_uuid'])
                    ->first();

                if ($poItemRecord) {
                    $this->createPurchaseOrderStatistic($purchaseOrder, $poItemRecord, $auth, [
                        'status'      => PurchaseOrdersStatus::IN_DISCUSS->value,
                        'received_by' => $auth->id,
                        'received_at' => now(),
                    ]);
                }

                $this->createSupplyStatistic($purchaseOrder, $item, $auth, [
                    'status'      => PurchaseOrdersStatus::DRAFT->value,
                    'received_by' => $auth->id,
                    'received_at' => now(),
                    'supply_uuid'         => $supply->uuid,
                    'supplier_uuid' => $item['supplier_uuid'] ?? null,
                ]);
            }

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

            \App\Models\PurchaseOrderNotification::where('purchase_order_uuid', $purchaseOrder->uuid)
                ->where('status', PurchaseOrdersStatus::IN_DISCUSS->value)
                ->delete();

            $notifMessage = $isFullySupplied
                ? "Un approvisionnement a été créé pour la commande (Référence : {$purchaseOrder->reference})."
                : "Un approvisionnement partiel a été créé pour la commande (Référence : {$purchaseOrder->reference}).";

            $this->sendNotification(
                order: $purchaseOrder,
                status: PurchaseOrdersStatus::IN_DISCUSS->value,
                message: $notifMessage,
                userId: $auth->id,
                target: 'purchases_orders',
            );

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
                'purchaseOrder.warehouseTo.natures',
                'purchaseOrder.warehouseFrom.natures',
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
     * @permission_desc Modifier les approvisionnements
     */
    public function update_supplies(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $supply = Supply::with('items')->where('uuid', $uuid)->firstOrFail();
        $purchaseOrder = $supply->purchaseOrder;
        $supplyType = $purchaseOrder->type === 'internal' ? 'internal' : 'external';

        if ($supplyType === 'internal') {
            $warehouseUuidSave = $purchaseOrder->warehouse_from;
        } else {
            $warehousePrimary = Warehouse::where('is_primary', true)->firstOrFail();
            $warehouseUuidSave = $warehousePrimary->uuid;
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|uuid',
            'items.*.sell_price' => 'nullable|numeric|min:0',
            'items.*.quantity_supplied' => 'required|numeric|min:1',
            'items.*.purchase_price' => $supplyType === 'external' ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.supplier_uuid' => $supplyType === 'external' ? 'required|uuid' : 'nullable|uuid',
            'scanned_documents' => 'nullable|array|min:1',
            'scanned_documents.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'partial_validation_reason' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();
            $partialItems = [];

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

                $warehousePrimary = Warehouse::where('is_primary', true)->firstOrFail();
                $warehouseUuid = $warehousePrimary->uuid;

                $pointStock = ProductPoint::where('produit_uuid', $item['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;
                if ($purchaseOrder->type === 'internal') {
                    if ($item['quantity_supplied'] > $pointStock) {
                        $qtyStocks = rtrim(rtrim($pointStock, '0'), '.');
                        return response()->json([
                            'errors' => [
                                'items' => [
                                    $index => [
                                        'quantity_supplied' => [
                                            "La quantité approvisionnée ({$item['quantity_supplied']}) ne peut pas dépasser le stock disponible."
                                        ]
                                    ]
                                ]
                            ]
                        ], 422);
                    }
                }

                if ($item['quantity_supplied'] < $poItem->quantity) {
                    $partialItems[] = $poItem->product->name ?? 'Produit inconnu';
                }
            }

            $isFullySupplied = empty($partialItems);


            $totalPrice = $validated['items'][0]['purchase_price'] ?? 0;
            $unitPrice = $validated['items'][0]['quantity_supplied'] > 0 ? $totalPrice / $validated['items'][0]['quantity_supplied'] : 0;

            $supply->update([
                'notes' => $validated['notes'] ?? $supply->notes,
                'warehouse_uuid' => $warehouseUuidSave,
                'status' => $isFullySupplied ? 'draft' : 'draft',
                'partially_validated_by' => $isFullySupplied ? null : $auth->id,
                'partial_validation_reason' => $isFullySupplied ? null :
                    ($validated['partial_validation_reason'] ??
                        'Certains produits n’ont pas été approvisionnés complètement : ' . implode(', ', $partialItems)),
                'unit_price' => $unitPrice,
                'updated_by' => $auth->id,
            ]);

            $supply->items()->delete();
            foreach ($validated['items'] as $item) {
                SupplyItem::create([
                    'supply_uuid' => $supply->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_supplied' => $item['quantity_supplied'],
                    'purchase_price' => $item['purchase_price'] ?? 0,
                    'supplier_uuid' => $item['supplier_uuid'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'created_by' => $auth->id,
                    'sell_price' => $item['sell_price'] ?? 0,
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

            \App\Models\PurchaseOrderNotification::where('purchase_order_uuid', $purchaseOrder->uuid)
                ->where('status', PurchaseOrdersStatus::IN_DISCUSS->value)
                ->delete();

            $notifMessage = $isFullySupplied
                ? "Un approvisionnement a été créé pour la commande (Référence : {$purchaseOrder->reference})."
                : "Un approvisionnement partiel a été créé pour la commande (Référence : {$purchaseOrder->reference}).";

            $this->sendNotification(
                order: $purchaseOrder,
                status: PurchaseOrdersStatus::IN_DISCUSS->value,
                message: $notifMessage,
                userId: $auth->id,
                target: 'purchases_orders',
            );

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
     * @permission_desc Supprimer les approvisionnements
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

            $supply = Supply::with(['items', 'medias', 'purchaseOrder'])->where('uuid', $uuid)->firstOrFail();

            DB::beginTransaction();

            foreach ($supply->items as $item) {
                Product::where('uuid', $item->product_uuid)
                    ->decrement('stock_quantity', $item->quantity_supplied);
            }

            foreach ($supply->medias as $media) {
                if (\Storage::disk($media->disk)->exists($media->path)) {
                    \Storage::disk($media->disk)->delete($media->path);
                }
                $media->delete();
            }

            StatisticSupply::where('supply_uuid', $uuid)->delete();

            if ($supply->purchaseOrder) {
                StatisticsPurchaseOrder::where('purchase_order_uuid', $supply->purchaseOrder->uuid)->delete();
                $supply->purchaseOrder->delete();
            }

            $supply->items()->delete();
            $supply->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "L’approvisionnement et ses statistiques ont été supprimés avec succès."
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
     * @permission_desc Rejetter les approvisionnements
     */
    public function rejected_supplies(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ], [
            'rejection_reason.required' => "La raison du rejet est obligatoire.",
            'rejection_reason.string'   => "La raison doit être une chaîne de caractères.",
        ]);

        $supply = Supply::with(['purchaseOrder', 'items'])->where('uuid', $uuid)->firstOrFail();
        $purchaseOrder = $supply->purchaseOrder;

        $supply->update([
            'status'           => SupplyStatus::REJECTED->value,
            'rejection_reason' => $validated['rejection_reason'],
            'rejected_by'      => $auth->id,
        ]);

        foreach ($supply->items as $item) {
            $this->createSupplyStatistic($purchaseOrder, $item, $auth, [
                'status'              => SupplyStatus::REJECTED->value,
                'purchase_order_uuid' => $purchaseOrder->uuid ?? null,
                'supply_uuid'         => $supply->uuid,
                'rejected_by'         => $auth->id,
                'rejected_at'         => now(),
                'supplier_uuid'       => $item->supplier_uuid ?? null,
            ]);
        }

        $notifMessage = "L'approvisionnement de la commande (Référence : {$purchaseOrder->reference}) a été rejeté.";

        $this->sendNotification(
            order: $purchaseOrder,
            status: PurchaseOrdersStatus::REJECTED->value,
            message: $notifMessage,
            userId: $auth->id,
            target: 'purchases_orders',
        );

        return response()->json([
            'message' => 'Approvisionnement rejeté avec succès.',
            'data' => $supply
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::validate_supply
     * @permission_desc Valider les approvisionnements
     */
    public function validate_supply(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate(['password' => 'required|string']);
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $supply = Supply::with('items.product', 'purchaseOrder.items')->findOrFail($uuid);
            $purchaseOrder = $supply->purchaseOrder;

            if (!$purchaseOrder) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Commande introuvable.'
                ], 404);
            }

            $totalAdded = 0;
            $partial = false;

            foreach ($supply->items as $item) {

                $poItem = $purchaseOrder->items->firstWhere('product_uuid', $item->product_uuid);

                if (!$poItem) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Produit introuvable dans la commande pour {$item->product->name}."
                    ], 422);
                }

                if ($item->quantity_supplied < $poItem->quantity) {
                    $partial = true;
                }

                if ($supply->type === PurchaseOrdersType::EXTERNAL->value) {
                    $warehousePrimary = Warehouse::where('is_primary', true)->firstOrFail();

                    $productPoint = ProductPoint::where('produit_uuid', $item->product_uuid)
                        ->where('point_uuid', $warehousePrimary->uuid)
                        ->first();

                    if ($productPoint) {
                        $productPoint->increment('quantity', $item->quantity_supplied);
                    }

                    $totalAdded += $item->quantity_supplied;

                    $remaining = max($poItem->quantity - $item->quantity_supplied, 0);

                    $poItem->update([
                        'quantity_remaining' => $remaining,
                        'updated_by' => $auth->id
                    ]);
                }

                if ($supply->type === PurchaseOrdersType::INTERNAL->value) {
                    $warehouseFrom = Warehouse::findOrFail($purchaseOrder->warehouse_from);
                    $warehouseTo = Warehouse::where('is_primary', true)->firstOrFail();

                    $productPointFrom = ProductPoint::where('produit_uuid', $item->product_uuid)
                        ->where('point_uuid', $warehouseTo->uuid)
                        ->first();

                    if (!$productPointFrom || $productPointFrom->quantity < $item->quantity_supplied) {
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Stock insuffisant dans l'entrepôt source pour {$item->product->name}."
                        ], 422);
                    }
                    $productPointFrom->decrement('quantity', $item->quantity_supplied);

                    $productPointTo = ProductPoint::where('produit_uuid', $item->product_uuid)
                        ->where('point_uuid', $warehouseFrom->uuid)
                        ->first();

                    if ($productPointTo) {
                        $productPointTo->increment('quantity', $item->quantity_supplied);
                    }

                    $totalAdded += $item->quantity_supplied;

                    $remaining = max($poItem->quantity - $item->quantity_supplied, 0);

                    $poItem->update([
                        'quantity_remaining' => $remaining,
                        'updated_by' => $auth->id
                    ]);
                }
            }

            $supplyStatus = $partial ? 'partially_validated' : 'validated';
            $orderStatus  = $partial ? 'partially_closed' : 'closed';

            $supply->update([
                'status' => $supplyStatus,
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'validated_at' => now()
            ]);

            $canClose = $purchaseOrder->items()->sum('quantity_remaining') === 0;
            $orderStatus = $canClose ? 'closed' : ($partial ? 'partially_closed' : $purchaseOrder->status);

            $purchaseOrder->update([
                'status' => $orderStatus,
                'closed_at' => $canClose ? now() : null,
                'updated_by' => $auth->id,
            ]);

            foreach ($supply->items as $item) {
                $this->createSupplyStatistic($purchaseOrder, $item, $auth, [
                    'status'              => $supplyStatus,
                    'purchase_order_uuid' => $purchaseOrder->uuid,
                    'validated_by'        => $auth->id,
                    'supply_uuid'         => $supply->uuid,
                    'validated_at'        => now(),
                    'supplier_uuid'       => $item->supplier_uuid ?? null,
                ]);

                $poItemRecord = $purchaseOrder->items->firstWhere('product_uuid', $item->product_uuid);
                if ($poItemRecord) {
                    $this->createPurchaseOrderStatistic($purchaseOrder, $poItemRecord, $auth, [
                        'status'       => $orderStatus,
                        'approved_by'  => $auth->id,
                        'approved_at'  => now(),
                        'validated_by' => $auth->id,
                        'validated_at' => now(),
                    ]);
                }
            }

            if ($purchaseOrder->parent_uuid) {
                $parent = PurchaseOrder::find($purchaseOrder->parent_uuid);

                if ($parent) {
                    $allChildrenClosed = $parent->children()
                            ->whereNotIn('status', ['closed', 'partially_closed'])
                            ->count() === 0;

                    $allQuantitiesUsed = $parent->items()->sum('quantity_remaining') === 0;

                    $parentStatus = ($allChildrenClosed && $allQuantitiesUsed) ? 'closed' : 'partially_closed';

                    $parent->update([
                        'status' => $parentStatus,
                        'closed_at' => ($allChildrenClosed && $allQuantitiesUsed) ? now() : null,
                        'updated_by' => $auth->id,
                    ]);
                }
            }

            $notifMessage = $partial
                ? "L'approvisionnement de la commande (Référence : {$purchaseOrder->reference}) a été partiellement validé."
                : "L'approvisionnement de la commande (Référence : {$purchaseOrder->reference}) a été entièrement validé.";

            $this->sendNotification(
                order: $purchaseOrder,
                status: $orderStatus,
                message: $notifMessage,
                userId: $auth->id,
                target: 'purchases_orders',
            );

            DB::commit();

            return response()->json([
                'message' => $partial
                    ? "Approvisionnement partiellement validé."
                    : "Approvisionnement entièrement validé.",
                'supply_status' => $supplyStatus,
                'order_status' => $orderStatus,
                'total_added' => $totalAdded,
                'supply' => $supply->load('items.product')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur validation approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error' => "Erreur lors de la validation.",
                'details' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission SupplyController::cancel_supply
     * @permission_desc Annuler les approvisionnements
     */
    public function cancel_supply(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_cancel' => 'required|string|max:255',
        ], [
            'reason_cancel.required' => 'Le motif d\'annulation est obligatoire.',
        ]);

        $supply = Supply::with(['purchaseOrder.items', 'items'])->where('uuid', $uuid)->firstOrFail();
        $purchaseOrder = $supply->purchaseOrder;

        try {
            DB::beginTransaction();

            $supply->update([
                'status'        => SupplyStatus::CANCELLED->value,
                'reason_cancel' => $validated['reason_cancel'],
                'cancelled_by'  => $auth->id,
                'updated_by'    => $auth->id,
            ]);

            if ($purchaseOrder) {
                $purchaseOrder->update([
                    'status'     => PurchaseOrdersStatus::CANCEL->value,
                    'updated_by' => $auth->id,
                ]);

                foreach ($supply->items as $item) {
                    $this->createSupplyStatistic($purchaseOrder, $item, $auth, [
                        'status'              => SupplyStatus::CANCELLED->value,
                        'purchase_order_uuid' => $purchaseOrder->uuid,
                        'cancelled_by'        => $auth->id,
                        'supply_uuid'         => $supply->uuid,
                        'cancelled_at'        => now(),
                        'supplier_uuid'       => $item->supplier_uuid ?? null,
                    ]);

                    $poItemRecord = $purchaseOrder->items->firstWhere('product_uuid', $item->product_uuid);
                    if ($poItemRecord) {
                        $this->createPurchaseOrderStatistic($purchaseOrder, $poItemRecord, $auth, [
                            'status'       => PurchaseOrdersStatus::CANCEL->value,
                            'cancelled_by' => $auth->id,
                            'cancelled_at' => now(),
                        ]);
                    }
                }

                $notifMessage = "L'approvisionnement de la commande (Référence : {$purchaseOrder->reference}) a été annulé.";

                $this->sendNotification(
                    order: $purchaseOrder,
                    status: PurchaseOrdersStatus::CANCEL->value,
                    message: $notifMessage,
                    userId: $auth->id,
                    target: 'purchases_orders',
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Approvisionnement annulé avec succès.',
                'data'    => $supply
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur annulation approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error'   => 'Erreur lors de l’annulation de l’approvisionnement.',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission SupplyController::print_supplies
     * @permission_desc Exporter les détails de l'approvisionnement en PDF
     */
    public function print_supplies(Request $request, string $uuid)
    {
        $auth = auth()->user();
        try {
            // Charger l'approvisionnement avec les relations
            $supply = Supply::with([
                'items.product',
                'items.supplier',
                'purchaseOrder.items',
                'purchaseOrder.warehouseTo.natures',
                'purchaseOrder.warehouseFrom.natures',
                'creator',
                'updater',
                'partially_validated',
                'rejector',
                'validator',
                'medias',
                'warehouse',

            ])->findOrFail($uuid);

            $supply_uuid  = $supply->uuid;
            $fileName = strtoupper('APPROVISIONNEMENT-N°' . strtoupper($supply->reference) . '-'. '.pdf');
            $folderPath = 'storage/details-approvisionnements';
            $filePath     = $folderPath . '/' . $fileName;

            // Créer le dossier si nécessaire (recursive = true)
            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                    throw new \RuntimeException(sprintf('Impossible de créer le répertoire "%s"', $folderPath));
                }
            }

            $footer = 'pdfs.reports.factures.footer';
            // Générer le PDF via la fonction save_browser_shot_pdf
            save_browser_shot_pdf(
                view: 'pdfs.details-approvisionnements.details-approvisionnements',
                data: ['supply' => $supply],
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
            );

            // Vérifier si le fichier a été généré
            if (!file_exists($filePath)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Le fichier PDF n'a pas été généré."
                ], 500);
            }

            $pdf = PdfDocument::where('order_uuid', $supply->uuid)
                ->where('name', 'DETAILS-SUPPLY')
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
                    'name'       => 'DETAILS-SUPPLY',
                    'order_uuid' => $supply->uuid,
                    'disk'       => 'public',
                    'path'       => $filePath,
                    'filename'   => $fileName,
                    'mimetype'   => 'application/pdf',
                    'extension'  => 'pdf',
                    'created_by' => auth()->id(),
                ]);
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
                'document' => $pdf,
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

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ], [
            'rejection_reason.required' => "La raison du rejet est obligatoire.",
            'rejection_reason.string'   => "La raison doit être une chaîne de caractères.",
        ]);

        $supply = Supply::with(['purchaseOrder', 'items'])->where('uuid', $uuid)->firstOrFail();
        $purchaseOrder = $supply->purchaseOrder;

        $supply->update([
            'status'           => SupplyStatus::REJECTED->value,
            'rejection_reason' => $validated['rejection_reason'],
            'rejected_by'      => $auth->id,
        ]);

        foreach ($supply->items as $item) {
            $this->createSupplyStatistic($purchaseOrder, $item, $auth, [
                'status'              => SupplyStatus::REJECTED->value,
                'purchase_order_uuid' => $purchaseOrder->uuid ?? null,
                'supply_uuid'         => $supply->uuid,
                'rejected_by'         => $auth->id,
                'rejected_at'         => now(),
                'supplier_uuid'       => $item->supplier_uuid ?? null,
            ]);
        }

        $notifMessage = "L'approvisionnement de la commande (Référence : {$purchaseOrder->reference}) a été rejeté.";

        $this->sendNotification(
            order: $purchaseOrder,
            status: PurchaseOrdersStatus::REJECTED->value,
            message: $notifMessage,
            userId: $auth->id,
            target: 'purchases_orders',
        );

        return response()->json([
            'message' => 'Approvisionnement rejeté avec succès.',
            'data' => $supply
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::export_supply
     * @permission_desc Exporter les approvisionnements au format Excel
     */
    public function export_supply(Request $request)
    {
        $filter = SupplyFilterData::fromRequestSupply($request);
        $filename = 'LISTE-DES-APPROVISIONNEMENTS-' . now()->format('dmY') . '.xlsx';

        $supplyQuery = supply_filter($filter, false);

        Excel::store(new SuppliesExport($supplyQuery), $filename, 'exportsupply');
        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $filename,
            "url" => Storage::disk('exportsupply')->url($filename)
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

        $supply = Supply::with(['purchaseOrder.items', 'items'])->findOrFail($uuid);
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

        try {
            DB::beginTransaction();

            $supply->update([
                'transferred_at' => now(),
                'transferred_by' => $auth->id,
                'receiver_by'    => $purchaseOrder->created_by,
                'status'         => SupplyStatus::IN_PROGRESS->value,
            ]);

            if ($purchaseOrder) {
                $purchaseOrder->update([
                    'status'     => PurchaseOrdersStatus::IN_DISCUSS->value,
                    'updated_by' => $auth->id,
                ]);

                foreach ($supply->items as $item) {
                    $this->createSupplyStatistic($purchaseOrder, $item, $auth, [
                        'status'              => SupplyStatus::IN_PROGRESS->value,
                        'purchase_order_uuid' => $purchaseOrder->uuid,
                        'transfered_by'       => $auth->id,
                        'supply_uuid'         => $supply->uuid,
                        'transfered_at'       => now(),
                        'supplier_uuid'       => $item->supplier_uuid ?? null,
                    ]);

                    $poItemRecord = $purchaseOrder->items->firstWhere('product_uuid', $item->product_uuid);
                    if ($poItemRecord) {
                        $this->createPurchaseOrderStatistic($purchaseOrder, $poItemRecord, $auth, [
                            'status'        => PurchaseOrdersStatus::IN_DISCUSS->value,
                            'transfered_by' => $auth->id,
                            'transfered_at' => now(),
                        ]);
                    }
                }
            }

            $notifMessage = "L'approvisionnement de la commande (Référence : {$purchaseOrder->reference}) a été transféré.";

            $this->sendNotification(
                order: $purchaseOrder,
                status: PurchaseOrdersStatus::IN_DISCUSS->value,
                message: $notifMessage,
                userId: $auth->id,
                target: 'purchases_orders',
            );

            DB::commit();

            return response()->json([
                'message' => 'Approvisionnement transféré avec succès.',
                'data'    => $supply
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur transfert approvisionnement : ' . $e->getMessage());

            return response()->json([
                'error'   => 'Erreur lors du transfert de l’approvisionnement.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplyController::getProductSupplyStatistics
     * @permission_desc Afficher l'historique des approvisionnements d'un article
     */
    public function getProductSupplyStatistics(string $supplyUuid, string $productUuid)
    {
        $supply = Supply::with('purchaseOrder')->where('uuid', $supplyUuid)->firstOrFail();

        $statistics = StatisticSupply::with([
            'purchaseOrder:uuid,reference',
            'supply:uuid,supply_date',
            'product:uuid,name',
            'creator:id,nom_utilisateur',
            'transferer:id,nom_utilisateur',
            'validator:id,nom_utilisateur',
            'rejecter:id,nom_utilisateur',
            'receiver:id,nom_utilisateur',
            'canceller:id,nom_utilisateur'
        ])
            ->where('supply_uuid', $supplyUuid)
            ->where('product_uuid', $productUuid)
            ->latest()
            ->get();

        if ($statistics->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucune statistique d\'approvisionnement trouvée pour ce produit dans cet approvisionnement.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'supply_uuid' => $supplyUuid,
            'order_reference' => $supply->purchaseOrder?->reference,
            'product_uuid' => $productUuid,
            'statistics_history' => $statistics
        ], 200);
    }










}

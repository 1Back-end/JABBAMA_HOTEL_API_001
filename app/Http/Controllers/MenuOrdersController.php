<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\ProductPoint;
use App\Models\Supply;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
/**
 * @permission_category Composition des menus du restaurant
 */
class MenuOrdersController extends Controller
{

    public function MenuOrderStatus()
    {
        return response()->json([
            'status' => 'success',
            'data'   => MenuOrderStatus::toArray(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission MenuOrdersController::store
     * @permission_desc Créer la composition d'un menu du restaurant
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            // 🔹 Validation de base
            $validated = $request->validate([
                'menus_restaurant_uuid' => 'required|exists:menus_restaurants,uuid',
                'warehouse_uuid'        => 'nullable|exists:warehouses,uuid',
                'items'                 => 'required|array',
                'items.*.product_uuid'  => 'required|exists:produits,uuid',
                'items.*.quantity_used' => 'required|numeric|min:1',
                'description' => 'nullable|string',
            ]);

            // 🔹 Récupérer l'entrepôt utilisé pour la cuisine
            $warehouseUuid = $validated['warehouse_uuid'] ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;
            $stockErrors = [];
            // 🔹 Vérification des stocks pour chaque article
            foreach ($validated['items'] as $index => $item) {
                $pointStock = (float) ProductPoint::where('produit_uuid', $item['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                $quantitySupplied = (float) $item['quantity_used'];

                if ($quantitySupplied > $pointStock) {
                    $stockErrors[$index]['quantity_used'][] = "La quantité demandée ({$quantitySupplied}) dépasse le stock disponible ({$pointStock}).";
                }
            }

            if (!empty($stockErrors)) {
                return response()->json([
                    'status' => 'validation_error',
                    'errors' => [
                        'items' => $stockErrors
                    ]
                ], 422);
            }

            $menuOrder = MenuOrder::create([
                'menus_restaurant_uuid' => $validated['menus_restaurant_uuid'],
                'warehouse_uuid'        => $warehouseUuid,
                'status'                => \App\Enums\MenuOrderStatus::PENDING->value,
                'created_by'            => $auth->id,
                'description'           => $validated['description'],
            ]);

            // 🔹 Création des items du MenuOrder
            foreach ($validated['items'] as $item) {
                MenuOrderItem::create([
                    'menu_order_uuid' => $menuOrder->uuid,
                    'product_uuid'    => $item['product_uuid'],
                    'quantity_used'   => $item['quantity_used'],
                    'created_by'      => $auth->id,
                    'updated_by'      => $auth->id,
                ]);

            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Composition de menu créée avec succès.',
                'data'    => $menuOrder->load('items')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Erreur creation approvisionnement : ' . $e->getMessage());
            // ✅ Gestion propre des erreurs de validation
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            // ✅ Gestion des autres exceptions
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission MenuOrdersController::update
     * @permission_desc Modifier la composition d'un menu du restaurant
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            \Log::info("Début update MenuOrder UUID: {$uuid}");

            $menuOrder = MenuOrder::where('uuid', $uuid)->firstOrFail();
            \Log::info("MenuOrder trouvé:", ['menuOrder' => $menuOrder->toArray()]);

            // 🔹 Validation
            $validated = $request->validate([
                'menus_restaurant_uuid' => 'required|exists:menus_restaurants,uuid',
                'warehouse_uuid'        => 'nullable|exists:warehouses,uuid',
                'items'                 => 'required|array',
                'items.*.product_uuid'  => 'required|exists:produits,uuid',
                'items.*.quantity_used' => 'required|numeric|min:1',
                'description'           => 'nullable|string',
            ]);
            \Log::info("Données validées:", ['validated' => $validated]);

            $warehouseUuid = $validated['warehouse_uuid'] ?? $menuOrder->warehouse_uuid;
            \Log::info("Entrepôt utilisé:", ['warehouseUuid' => $warehouseUuid]);

            // 🔹 Vérification des stocks
            $stockErrors = [];
            foreach ($validated['items'] as $index => $item) {
                $pointStock = (float) ProductPoint::where('produit_uuid', $item['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                $quantitySupplied = (float) $item['quantity_used'];

                \Log::info("Vérification stock:", [
                    'product_uuid' => $item['product_uuid'],
                    'requested'    => $quantitySupplied,
                    'available'    => $pointStock
                ]);

                if ($quantitySupplied > $pointStock) {
                    $stockErrors[$index]['quantity_used'][] = "La quantité demandée ({$quantitySupplied}) dépasse le stock disponible ({$pointStock}).";
                }
            }

            if (!empty($stockErrors)) {
                \Log::warning("Erreur stock:", ['stockErrors' => $stockErrors]);
                return response()->json([
                    'status' => 'validation_error',
                    'errors' => ['items' => $stockErrors]
                ], 422);
            }

            // 🔹 Mettre à jour le MenuOrder
            $menuOrder->update([
                'menus_restaurant_uuid' => $validated['menus_restaurant_uuid'],
                'warehouse_uuid'        => $warehouseUuid,
                'description'           => $validated['description'],
                'updated_by'            => $auth->id,
            ]);
            \Log::info("MenuOrder mis à jour:", ['menuOrder' => $menuOrder->toArray()]);

            // 🔹 Supprimer les anciens items
            $deleted = $menuOrder->items()->delete();
            \Log::info("Items supprimés:", ['count' => $deleted]);

            // 🔹 Créer les nouveaux items
            foreach ($validated['items'] as $item) {
                $newItem = MenuOrderItem::create([
                    'menu_order_uuid' => $menuOrder->uuid,
                    'product_uuid'    => $item['product_uuid'],
                    'quantity_used'   => $item['quantity_used'],
                    'created_by'      => $auth->id,
                    'updated_by'      => $auth->id,
                ]);
                \Log::info("Item créé:", ['item' => $newItem->toArray()]);
            }

            DB::commit();
            \Log::info("Mise à jour terminée avec succès");

            return response()->json([
                'status'  => 'success',
                'message' => 'Composition de menu mise à jour avec succès.',
                'data'    => $menuOrder->load('items')
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            \Log::error('Erreur validation update composition menu : ' . $e->getMessage(), ['errors' => $e->errors()]);
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Exception update composition menu : ' . $e->getMessage(), [
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }




    /**
     * Display a listing of the resource.
     * @permission MenuOrdersController::show
     * @permission_desc Afficher les détails de la composition d'un menu du restaurant
     */
    public function show(string $uuid)
    {
        $menu_orders = MenuOrder::with([
            'menus_restaurant',
            'warehouse',
            'validator',
            'items.product',
            'rejector',
            'bufferItems',
            'creator',
            'updater',

        ])
            ->where('uuid', $uuid)->firstOrFail();

        if (!$menu_orders) {
            return response()->json([
                'success' => false,
                'message' => 'Composition de menus non trouvée.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'menu_orders' => $menu_orders,
            'message' => 'Composition de menus récupérée avec succès.'
        ]);

    }

    /**
     * Display a listing of the resource.
     * @permission MenuOrdersController::index
     * @permission_desc Afficher la liste des compositions de menus du restaurant
     */
    public function index(Request $request)
    {
        $auth = auth()->user();

        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $roleIds = $auth->roles->pluck('id');
        $start_date = Carbon::parse($request->input('start_date'))->startOfDay();
        $end_date = Carbon::parse($request->input('end_date'))->endOfDay();

        $query = MenuOrder::with([
            'menus_restaurant',
            'warehouse',
            'validator',
            'items.product',
            'rejector',
            'bufferItems',
            'creator',
            'updater',
        ]);

        if ($request->filled('menus_restaurant_uuid')) {
            $query->where('menus_restaurant_uuid', $request->menus_restaurant_uuid);
        }

        if ($request->filled('warehouse_uuid')) {
            $query->where('warehouse_uuid', $request->warehouse_uuid);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$start_date, $end_date]);
        }

        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_menu_orders')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('menus_restaurant_uuid', 'like', "%{$search}%")
                    ->orWhere('warehouse_uuid', 'like', "%{$search}%")

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

                    ->orWhereHas('menus_restaurant', function ($mr) use ($search) {
                        $mr->where('uuid', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
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

    }



}

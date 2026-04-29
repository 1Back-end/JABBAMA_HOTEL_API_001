<?php

namespace App\Http\Controllers;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\TypeClientsForPaiment;
use App\Enums\VirtualOrderMenuRestaurantStatus;
use App\Models\DrinksVirtualTemp;
use App\Models\InvoiceForMenuOrder;
use App\Models\LastStatusDrinksMenusRestaurant;
use App\Models\LastStatusItemsMenusRestaurant;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\MenuRestaurant;
use App\Models\MenuVirtualTemp;
use App\Models\OrderMenuItemStatus;
use App\Models\OrderMenuItemStatusForDrink;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantDefectiveDrink;
use App\Models\OrderMenuRestaurantDefectiveItem;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\Role;
use App\Models\SettingRestaurant;
use App\Models\StatisticsOrderStatusDrink;
use App\Models\StatisticsOrderStatusMenuRestaurant;
use App\Models\User;
use App\Models\VirtualOrderMenuRestaurant;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
/**
 * @permission_category Gestion des commandes du restaurant
 * @permission_module Gestion du restaurant
 */
class OrderMenuRestaurantController extends Controller
{
    private function clearExpiredVirtualTemps()
    {
        $minutes = (int) SettingRestaurant::where('key', 'logout_period')->where('is_active', true)->value('value') ?? 30;
        $limit = now()->subMinutes($minutes);
        return MenuVirtualTemp::where('last_activity_at', '<', $limit)
            ->whereNotNull('reservation_uuid')
            ->whereNull('order_menu_restaurant_uuid')
            ->delete();
    }
    private function getLogoutMinutes()
    {
        $setting = SettingRestaurant::where('key', 'logout_period')
            ->where('is_active', true)
            ->first();

        return $setting ? (int)$setting->value : 30;
    }

    public function removeReservationItem(Request $request)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reservation_uuid' => ['required', 'uuid'],
            'menus_restaurant_uuid' => ['required', 'uuid'],
        ]);

        $deleted = MenuVirtualTemp::where('reservation_uuid', $validated['reservation_uuid'])
            ->where('menus_restaurant_uuid', $validated['menus_restaurant_uuid'])
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Réservation supprimée',
            'deleted_rows' => $deleted
        ]);
    }
    public function removeDrinkReservationItem(Request $request)
    {
        $auth = auth()->user();
        $validated = $request->validate([
            'reservation_uuid' => ['required', 'uuid'],
            'product_uuid' => ['required', 'uuid'],
        ]);
        $deleted = DrinksVirtualTemp::where('reservation_uuid', $validated['reservation_uuid'])
            ->where('product_uuid', $validated['product_uuid'])
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Boisson supprimée de la réservation',
            'deleted_rows' => $deleted
        ]);
    }

    private function verifyBarStock(array $drinks, string $warehouseDrinkUuid): array
    {
        $stockErrors = [];

        foreach ($drinks as $drink) {

            $product = Product::find($drink['product_uuid']);
            $requiredQuantity = $drink['quantity'];

            $realStock = (float) ProductPoint::where('produit_uuid', $drink['product_uuid'])
                ->where('point_uuid', $warehouseDrinkUuid)
                ->value('quantity') ?? 0;

            // 🔥 stock déjà réservé (comme menus)
            $reservedStock = (float) VirtualOrderMenuRestaurant::where('product_uuid', $drink['product_uuid'])
                ->where('status', 'pending')
                ->sum('quantity_reserved');

            $availableStock = $realStock - $reservedStock;

            if ($requiredQuantity > $availableStock) {
                $stockErrors[] = [
                    'product_uuid' => $drink['product_uuid'],
                    'product_name' => $product?->name ?? 'Inconnu',
                    'quantity_required' => $requiredQuantity,
                    'quantity_available' => $availableStock,
                ];
            }
        }

        return $stockErrors;
    }

    private function verifyMenuStock(array $menus, string $warehouseUuid): array
    {
        $stockErrors = [];

        foreach ($menus as $menuInput) {
            $menu = MenuRestaurant::find($menuInput['menus_restaurant_uuid']);
            $menuItems = MenuOrderItem::with('product')
                ->where('menus_restaurant_uuid', $menuInput['menus_restaurant_uuid'])
                ->get();

            $menuQuantity = $menuInput['quantity'] ?? 0;

            foreach ($menuItems as $item) {
                $productUuid = $item->product_uuid ?? null;
                $productName = $item->product?->name ?? 'Inconnu';
                $totalQuantityUsed = $menuQuantity * ($item->quantity_used ?? 0);

                $pointStock = (float) ProductPoint::where('produit_uuid', $productUuid)
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                if ($totalQuantityUsed > $pointStock) {
                    $stockErrors[] = [
                        'menu_uuid' => $menuInput['menus_restaurant_uuid'],
                        'menu_name' => $menu->name ?? 'Menu inconnu',
                        'product_uuid' => $productUuid,
                        'product_name' => $productName,
                        'quantity_required' => $totalQuantityUsed,
                        'quantity_in_stock' => $pointStock,
                    ];
                }
            }
        }

        return $stockErrors;
    }

    public function checkBarStockOnly(Request $request)
    {
        $auth = auth()->user();

        $reservationUuid = $request->reservation_uuid ?? (string) Str::uuid();

        try {
            $validated = $request->validate([
                'reservation_uuid' => ['nullable', 'uuid'],
                'drinks' => ['required', 'array', 'min:1'],
                'drinks.*.product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
                'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $warehouseUuid = $warehouse->uuid;

            $stockErrors = [];

            foreach ($validated['drinks'] as $drink) {

                $product = Product::where('uuid', $drink['product_uuid'])->first();
                if (!$product) continue;

                $requiredQty = (float) $drink['quantity'];

                $realStock = (float) ProductPoint::where('produit_uuid', $product->uuid)
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                $reservedStock = (float) DrinksVirtualTemp::where('product_uuid', $product->uuid)
                    ->where('status', 'pending')
                    ->where(function ($query) use ($reservationUuid) {
                        if ($reservationUuid) {
                            $query->where('reservation_uuid', '!=', $reservationUuid);
                        }
                    })
                    ->sum('quantity_used');

                $availableStock = max(0, $realStock - $reservedStock);

                if ($requiredQty > $availableStock) {
                    $stockErrors[] = [
                        'product_name' => $product->name,
                        'quantity_required' => $requiredQty,
                        'quantity_in_stock' => $availableStock,
                    ];
                }
            }

            // ❌ STOP si stock insuffisant
            if (!empty($stockErrors)) {
                return response()->json([
                    'status' => 'error',
                    'message' => collect($stockErrors)
                        ->map(fn($e) =>
                        "Boisson « {$e['product_name']} » insuffisante (stock: {$e['quantity_in_stock']})"
                        )->implode(' | '),
                    'details' => $stockErrors,
                ], 422);
            }

            // ✅ UPSERT SANS DELETE
            foreach ($validated['drinks'] as $drink) {

                DrinksVirtualTemp::updateOrCreate(
                    [
                        'reservation_uuid' => $reservationUuid,
                        'product_uuid' => $drink['product_uuid'],
                        'type' => 'initial'
                    ],
                    [
                        'quantity' => $drink['quantity'],
                        'quantity_used' => $drink['quantity'],
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'order_menu_restaurant_uuid' => null,
                    ]
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Stock bar mis à jour temporairement',
                'reservation_uuid' => $reservationUuid,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            \Log::error('checkBarStockOnly error', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la vérification du stock bar',
            ], 500);
        }
    }

    public function checkStockOnly(Request $request)
    {
        $auth = auth()->user();
        $reservationUuid = $request->reservation_uuid ?? (string) \Illuminate\Support\Str::uuid();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'reservation_uuid' => ['nullable', 'uuid'],
                'menus' => ['required', 'array', 'min:1'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            // 🔹 Warehouse
            $warehouseUuid = Warehouse::where('is_used_for_restaurant', true)
                ->value('uuid');

            $menusUuid = collect($validated['menus'])->pluck('menus_restaurant_uuid');

            $menuItems = MenuOrderItem::with('product')
                ->whereIn('menus_restaurant_uuid', $menusUuid)
                ->get()
                ->groupBy('menus_restaurant_uuid');

            $results = [];

            foreach ($validated['menus'] as $menuInput) {

                $menu = MenuRestaurant::find($menuInput['menus_restaurant_uuid']);
                if (!$menu) continue;

                $menuQuantity = (int) $menuInput['quantity'];
                $composition = [];

                foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                    $totalUsed = $menuQuantity * $item->quantity_used;

                    $composition[] = [
                        'product_uuid' => $item->product_uuid,
                        'product_name' => $item->product->name ?? 'Inconnu',
                        'total_quantity_used' => $totalUsed,
                    ];
                }

                $results[] = [
                    'menu' => $menu,
                    'composition' => $composition,
                ];
            }

            // 🔥 Vérification avec TA fonction
            $stockErrors = [];

            foreach ($results as $menuResult) {
                foreach ($menuResult['composition'] as $product) {

                    try {
                        $available = $this->checkStock(
                            $product['product_uuid'],
                            $warehouseUuid,
                            $product['total_quantity_used'],
                            $reservationUuid
                        );
                    } catch (\Exception $e) {

                        $stockErrors[] = [
                            'menu_name' => $menuResult['menu']->name,
                            'product_name' => $product['product_name'],
                            'quantity_required' => $product['total_quantity_used'],
                            'quantity_in_stock' => 0,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            // 🔴 Erreurs
            if (!empty($stockErrors)) {
                return response()->json([
                    'status' => 'error',
                    'message' => collect($stockErrors)
                        ->map(fn($e) => $e['error'])
                        ->implode(' | '),
                    'details' => $stockErrors
                ], 422);
            }

            // 🔥 Réservation
            foreach ($validated['menus'] as $menuInput) {
                foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                    MenuVirtualTemp::updateOrCreate(
                        [
                            'reservation_uuid' => $reservationUuid,
                            'menus_restaurant_uuid' => $menuInput['menus_restaurant_uuid'],
                            'product_uuid' => $item->product_uuid,
                            'type' => 'initial'
                        ],
                        [
                            'quantity' => $menuInput['quantity'],
                            'quantity_used' => $menuInput['quantity'] * $item->quantity_used,
                            'created_by' => $auth->id,
                            'is_not_used_stock' => false,
                            'updated_by' => $auth->id,
                            'order_menu_restaurant_uuid' => null,
                        ]
                    );
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Stock OK + réservation temporaire créée',
                'reservation_uuid' => $reservationUuid,
                'expires_in_minutes' => $this->getLogoutMinutes(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            \Log::error('checkStockOnly ERROR', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur serveur',
                'reservation_uuid' => $reservationUuid,
            ], 500);
        }
    }

    public function forceReleaseStock(Request $request)
    {
        $request->validate(['reservation_uuid' => 'required|uuid']);
        MenuVirtualTemp::where('reservation_uuid', $request->reservation_uuid)->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Stock libéré avec succès'
        ]);
    }


    public function forceReleaseOrderMenuRestaurant(Request $request)
    {
        $request->validate(['order_menu_restaurant_uuid' => 'required|uuid']);
        MenuVirtualTemp::where('order_menu_restaurant_uuid', $request->order_menu_restaurant_uuid)
            ->where('type', 'initial')->update(['is_not_used_stock' => false]);

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $request->order_menu_restaurant_uuid)
            ->where('type', 'editing')
            ->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Stock libéré avec succès'
        ]);
    }

    public function checkStockByOrder(Request $request)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'order_menu_restaurant_uuid' => ['required', 'uuid'],
            'menus' => ['required', 'array', 'min:1'],
            'menus.*.menus_restaurant_uuid' => ['required', 'uuid'],
            'menus.*.quantity' => ['required', 'numeric', 'min:1'],
        ]);

        $orderUuid = $validated['order_menu_restaurant_uuid'];

        $warehouseUuid = Warehouse::where('is_used_for_restaurant', true)
            ->value('uuid');

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)->update(['is_not_used_stock' => true]);

        $stockErrors = [];

        // 🔥 charger UNE FOIS toutes les compositions
        $menusUuid = collect($validated['menus'])->pluck('menus_restaurant_uuid');

        $menuItems = MenuOrderItem::with('product')
            ->whereIn('menus_restaurant_uuid', $menusUuid)
            ->get()
            ->groupBy('menus_restaurant_uuid');



        foreach ($validated['menus'] as $menuInput) {

            foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                $requiredQty = (int) $menuInput['quantity'] * (int) $item->quantity_used;

                // 🔥 stock réel
                $realStock = (float) ProductPoint::where('produit_uuid', $item->product_uuid)
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                $reservedStock = MenuVirtualTemp::where('product_uuid', $item->product_uuid)
                    ->where('status', 'pending')
                    ->where('is_not_used_stock', false)
                    ->where('order_menu_restaurant_uuid', '!=', $orderUuid)
                    ->sum('quantity_used');

                $availableStock = max(0, $realStock - $reservedStock);

                if ($requiredQty > $availableStock) {
                    $stockErrors[] = [
                        'product_name' => $item->product->name ?? 'Inconnu',
                        'quantity_required' => $requiredQty,
                        'quantity_available' => $availableStock,
                    ];
                }
            }
        }

        // ❌ ERREUR STOCK
        if (!empty($stockErrors)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stock insuffisant',
                'details' => $stockErrors
            ], 422);
        }

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where('type', 'editing')
            ->delete();

        // 🔥 RECREATE PROPRE
        foreach ($validated['menus'] as $menuInput) {

            foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                MenuVirtualTemp::updateOrCreate(
                    [
                        'order_menu_restaurant_uuid' => $orderUuid,
                        'menus_restaurant_uuid' => $menuInput['menus_restaurant_uuid'],
                        'product_uuid' => $item->product_uuid,
                        'type' => 'editing'
                    ],
                    [
                        'quantity' => $menuInput['quantity'],
                        'quantity_used' => $menuInput['quantity'] * $item->quantity_used,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]
                );
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Stock OK pour modification commande',
            'expires_in_minutes' => $this->getLogoutMinutes(),

        ]);
    }

    public function checkDrinksStockByOrder(Request $request)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'order_menu_restaurant_uuid' => ['required', 'uuid'],
            'drinks' => ['required', 'array', 'min:1'],
            'drinks.*.product_uuid' => ['required', 'uuid'],
            'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
        ]);

        $orderUuid = $validated['order_menu_restaurant_uuid'];

        \Log::info('Check Drinks Stock By Order', [
            'order_uuid' => $orderUuid,
            'payload' => $request->all()
        ]);

        $warehouse = Warehouse::where('is_bar_warehouse', true)
            ->firstOrFail();

        $warehouseUuid = $warehouse->uuid;

        $stockErrors = [];

        foreach ($validated['drinks'] as $drinkInput) {

            $product = Product::where('uuid', $drinkInput['product_uuid'])->first();

            if (!$product) continue;

            $requiredQty = (float) $drinkInput['quantity'];

            // 🔥 stock réel bar
            $realStock = (float) ProductPoint::where('produit_uuid', $product->uuid)
                ->where('point_uuid', $warehouseUuid)
                ->value('quantity') ?? 0;

            // 🔥 stock réservé (autres commandes)
            $reservedStock = (float) DrinksVirtualTemp::where('product_uuid', $product->uuid)
                ->where('order_menu_restaurant_uuid', '!=', $orderUuid)
                ->where('status', 'pending')
                ->sum('quantity_used');

            $availableStock = max(0, $realStock - $reservedStock);

            if ($requiredQty > $availableStock) {
                $stockErrors[] = [
                    'product_uuid' => $product->uuid, // 🔥 ajout important
                    'product_name' => $product->name ?? 'Inconnu',
                    'required' => (float) $requiredQty,   // 🔥 cast safe
                    'available' => (float) $availableStock, // 🔥 cast safe
                ];
            }
        }

        // ❌ erreur stock
        if (!empty($stockErrors)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stock boissons insuffisant',
                'details' => $stockErrors
            ], 422);
        }

        DrinksVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where('type', 'editing')
            ->delete();

        // 🔥 recréation réservation drinks
        foreach ($validated['drinks'] as $drinkInput) {
            DrinksVirtualTemp::updateOrCreate(
                [
                    'order_menu_restaurant_uuid' => $orderUuid,
                    'product_uuid' => $drinkInput['product_uuid'],
                    'type' => 'editing'
                ],
                [
                    'quantity' => $drinkInput['quantity'],
                    'quantity_used' => $drinkInput['quantity'],
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'status' => 'pending',
                ]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Stock boissons OK pour modification commande'
        ]);
    }

    private function checkStock($productUuid, $warehouseUuid, $quantity, $reservationUuid = null)
    {
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseUuid)
            ->value('quantity') ?? 0;

        $reservedStock = MenuVirtualTemp::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->where('is_not_used_stock', false)
            ->where(function ($q) use ($reservationUuid) {
                $q->whereNull('reservation_uuid')
                    ->orWhere('reservation_uuid', '!=', $reservationUuid);
            })
            ->sum('quantity_used');

        $availableStock = max(0, $realStock - $reservedStock);

        if ($quantity > $availableStock) {
            $productName = Product::where('uuid', $productUuid)->value('name') ?? 'Produit inconnu';
            throw new \Exception(
                "Stock insuffisant pour « {$productName} ». Disponible : {$availableStock}, Requis : {$quantity}"
            );
        }

        return $availableStock;
    }

    public function reserveStock($orderUuid, $itemUuid, $itemType, $productUuid, $quantity, $auth, $warehouseUuid)
    {
        // 🔹 stock réel
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseUuid)
            ->value('quantity') ?? 0;

        $reservedStock = (float) MenuVirtualTemp::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->sum('quantity_used');

        $availableStock = $realStock - $reservedStock;

        if ($quantity > $availableStock) {
            $productName = Product::where('uuid', $productUuid)->value('name') ?? 'Produit inconnu';
            throw new \Exception("Stock insuffisant pour « {$productName} ». Disponible : {$availableStock}, Requis : {$quantity}");
        }

        // ✅ UNIQUEMENT VIRTUEL
        VirtualOrderMenuRestaurant::create([
            'orders_menu_restaurant_uuid' => $orderUuid,
            'item_uuid' => $itemUuid,
            'item_type' => $itemType,
            'product_uuid' => $productUuid,
            'quantity_reserved' => $quantity,
            'quantity_exactly' => $quantity,
            'quantity_delivered_exactly' => 0,
            'status' => 'pending',
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_last_items' => true
        ]);
    }
    private function reserveDrinkStock($orderUuid, $drinkOrderUuid, $productUuid, $quantity, $auth, $warehouseDrinkUuid)
    {
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseDrinkUuid)
            ->value('quantity') ?? 0;

        $reservedStock = (float) VirtualOrderMenuRestaurant::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->sum('quantity_reserved');

        $availableStock = $realStock - $reservedStock;

        if ($quantity > $availableStock) {
            throw new \Exception(
                "Stock insuffisant pour boisson {$productUuid}. Disponible: {$availableStock}"
            );
        }

        VirtualOrderMenuRestaurant::create([
            'orders_menu_restaurant_uuid' => $orderUuid,
            'item_uuid' => $drinkOrderUuid,
            'item_type' => 'drink',
            'product_uuid' => $productUuid,
            'quantity_reserved' => $quantity,
            'quantity_exactly' => $quantity,
            'quantity_delivered_exactly' => 0,
            'status' => 'pending',
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_last_items' => true
        ]);
    }
    public function cancelRervationsAfterValidation(Request $request)
    {
        $validated = $request->validate([
            'order_menu_restaurant_uuid' => ['required', 'uuid'],
        ]);

        $orderUuid = $validated['order_menu_restaurant_uuid'];

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where(function ($query) {
                $query->where('type', 'initial')
                    ->orWhereNull('reservation_uuid');
            })
            ->update([
                'is_not_used_stock' => false,
            ]);

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where(function ($query) {
                $query->where('type', 'editing')
                    ->orWhereNull('reservation_uuid');
            })
            ->delete();

        DrinksVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where(function ($query) {
                $query->where('type', 'editing')
                    ->orWhereNull('reservation_uuid');
            })
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Modifications annulées, retour à l’état initial'
        ]);
    }
    public function cancelCurrentRervations(Request $request)
    {
        $validated = $request->validate([
            'reservation_uuid' => ['required', 'uuid'],
        ]);

        $reservationUuid = $validated['reservation_uuid'];
        MenuVirtualTemp::where('reservation_uuid', $reservationUuid)->where('type', 'initial')
            ->whereNull('order_menu_restaurant_uuid')->delete();

        DrinksVirtualTemp::where('reservation_uuid', $reservationUuid)->where('type', 'initial')
            ->whereNull('order_menu_restaurant_uuid')->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Retour à l’état initial effectué'
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::store
     * @permission_desc Créer une commande
     */
    public function store(Request $request)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            // 1. Validation
            $validated = $request->validate([
                'reservation_uuid' => ['nullable', 'uuid'],
                'type_clients_for_payment' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
                'restaurant_table_uuid' => ['nullable','uuid','required_if:type_clients_for_payment,' . ConsumptionType::DINE_IN->value, 'exists:restaurant_tables,uuid'],
                'order_menu_restaurant_date' => ['required', 'date_format:Y-m-d H:i:s'],
                'consumption_type' => ['required', 'string', new Enum(ConsumptionType::class)],
                'partners_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value, 'exists:restaurant_partners,uuid'],
                'free_client_for_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::FREE->value, 'exists:free_clients_restaurants,uuid'],
                'warehouse_uuid' => ['nullable', 'uuid', 'exists:warehouses,uuid'],
                'restaurant_room_uuid' => ['nullable', 'uuid', 'exists:restaurant_rooms,uuid'],
                'menus' => ['required', 'array', 'min:1'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
                'menus.*.unit_price' => ['nullable', 'numeric', 'min:0'],
                'remise' => ['nullable', 'numeric', 'min:0'],
                'full_name' => ['nullable', 'string', 'max:255'],
                'drinks' => ['nullable', 'array'],
                'drinks.*.product_uuid' => ['required_with:drinks', 'uuid', 'exists:produits,uuid'],
                'drinks.*.quantity' => ['required_with:drinks', 'numeric', 'min:1'],
                'drinks.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $warehouseUuid = $warehouse->uuid;

            $warehouseDrinks = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $warehouseDrinkUuid = $warehouseDrinks->uuid;

            Log::info($warehouseUuid);
            Log::info($warehouseDrinkUuid);

            if (!$warehouseUuid) {
                throw new \Exception("Aucun entrepôt configuré");
            }

            // ✅ Vérification stock
            if ($errors = $this->verifyMenuStock($validated['menus'], $warehouseUuid)) {
                return response()->json(['status'=>'error','message'=>'Stock insuffisant menus','details'=>$errors],422);
            }

            if (!empty($validated['drinks'])) {
                if ($errors = $this->verifyBarStock($validated['drinks'], $warehouseDrinkUuid)) {
                    return response()->json(['status'=>'error','message'=>'Stock boissons insuffisant','details'=>$errors],422);
                }
            }

            // 5. Création de la commande principale
            $order = OrderMenuRestaurant::create([
                'status' => \App\Enums\MenuOrderStatus::PENDING->value,
                'type_clients_for_payment' => $validated['type_clients_for_payment'],
                'consumption_type' => $validated['consumption_type'],
                'restaurant_table_uuid' => $validated['restaurant_table_uuid'] ?? null,
                'warehouse_uuid' => $warehouseUuid,
                'partners_restaurant_uuid' => $validated['partners_restaurant_uuid'] ?? null,
                'restaurant_room_uuid' => $validated['restaurant_room_uuid'] ?? null,
                'free_client_for_restaurant_uuid' => $validated['free_client_for_restaurant_uuid'] ?? null,
                'order_menu_restaurant_date' => $validated['order_menu_restaurant_date'],
                'remise' => $validated['remise'] ?? 0,
                'full_name' => $validated['full_name'] ?? null,
                'full_name_for_client_free' => $validated['full_name_for_client_free'] ?? null,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'reservation_uuid' => $validated['reservation_uuid'] ?? null,
            ]);

            // 6. Enregistrement des Menus et Composition Virtuelle
            foreach ($validated['menus'] as $mInput) {
                $menu = MenuRestaurant::find($mInput['menus_restaurant_uuid']);
                $isFree = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;

                $unitPrice = $mInput['unit_price'] ?? $menu->price ?? 0;
                $totalPrice = $isFree ? 0 : ($unitPrice * $mInput['quantity']);

                $orderItem = OrderMenuRestaurantItem::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'menus_restaurant_uuid' => $menu->uuid,
                    'quantity' => $mInput['quantity'],
                    'quantity_exactly' => $mInput['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'is_free' => $isFree,
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_last_items' => true
                ]);

                OrderMenuItemStatus::create([
                    'order_menu_restaurant_item_uuid' => $orderItem->uuid,
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'quantity' => $orderItem->quantity,
                    'quantity_exactly' => $orderItem->quantity,
                    'quantity_accumulated' => $orderItem->quantity,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);

                StatisticsOrderStatusMenuRestaurant::create([
                    'order_menu_restaurant_item_uuid' => $orderItem->uuid,
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'quantity' => $orderItem->quantity,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'transferred_at' => now(),
                    'make_transferred_by' => $auth->id,
                ]);

                // Réserve virtuelle basée sur la composition du menu
                $compositions = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
                foreach ($compositions as $comp) {
                    $requiredQty = $mInput['quantity'] * $comp->quantity_used;
                    $this->reserveStock($order->uuid, $orderItem->uuid, 'menu', $comp->product_uuid, $requiredQty, $auth, $warehouseUuid);
                }
            }

            // 7. Enregistrement des Boissons (Commande + Réserve Virtuelle)
            if (!empty($validated['drinks'])) {
                foreach ($validated['drinks'] as $drinkInput) {
                    $uPrice = $drinkInput['unit_price'] ?? 0;

                    // Création de la ligne de commande pour la boisson
                    $drinkOrder = OrderRestaurantDrink::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'product_uuid' => $drinkInput['product_uuid'],
                        'quantity' => $drinkInput['quantity'],
                        'quantity_exactly' => $drinkInput['quantity'],
                        'unit_price' => $uPrice,
                        'total_price' => $uPrice * $drinkInput['quantity'],
                        'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_last_items' => true
                    ]);

                    OrderMenuItemStatusForDrink::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                        'product_uuid' => $drinkInput['product_uuid'],
                        'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'quantity' => $drinkInput['quantity'],
                        'quantity_exactly' => $drinkInput['quantity'],
                        'quantity_accumulated' => $drinkInput['quantity'],
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]);

                    StatisticsOrderStatusDrink::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'product_uuid' => $drinkInput['product_uuid'],
                        'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                        'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'quantity' => $drinkInput['quantity'],
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'transferred_at' => now(),
                        'make_transferred_by' => $auth->id,
                    ]);

                    $this->reserveDrinkStock($order->uuid, $drinkOrder->uuid, $drinkInput['product_uuid'], $drinkInput['quantity'], $auth, $warehouseDrinkUuid);
                }
            }

            if ($request->filled('reservation_uuid')) {
                $affected = \DB::table('menu_virtuals_temp')
                    ->where('reservation_uuid', $request->reservation_uuid)
                    ->whereNull('deleted_at')
                    ->update([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'updated_by' => $auth->id,
                        'updated_at' => now()
                    ]);

                \Log::info('Force Update Result', ['lignes_touchees' => $affected]);
            }

            if ($request->filled('reservation_uuid')) {
                $affected = \DB::table('drinks_virtuals_temp')
                    ->where('reservation_uuid', $request->reservation_uuid)
                    ->whereNull('deleted_at')
                    ->update([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'updated_by' => $auth->id,
                        'updated_at' => now()
                    ]);

                \Log::info('Force Update Drinks Result', [
                    'lignes_touchees' => $affected
                ]);
            }

            // 8. Transfert automatique au Cuisinier
            $cuisinierRole = Role::where('name', 'CUISINIER')->first();
            if ($cuisinierRole && $recipient = $cuisinierRole->users()->first()) {
                $order->update([
                    'status' => MenuOrderStatus::TRANSFERRED->value,
                    'received_by' => $recipient->id,
                    'transfered_at' => now(),
                    'transfered_by' => $auth->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Commande enregistrée et transférée avec succès',
                'order_uuid' => $order->uuid
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur Store Order:', ['msg' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }



    public function checkStatusForMenus(Request $request, string $uuid)
    {
        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'menus' => ['required', 'array'],
            'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
            'menus.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
        $warehouseUuid = $warehouse->uuid;

        foreach ($validated['menus'] as $m) {

            $existingItem = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->where('menus_restaurant_uuid', $m['menus_restaurant_uuid'])->first();

            if (!$existingItem) continue;

            $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);

            \Log::info('=== DEBUG REDUCTION ===');

            $statuses = OrderMenuItemStatus::where('order_menu_restaurant_item_uuid', $existingItem->uuid)->get();

            $newQty = (int) $m['quantity'];
            $oldQty = (int) $existingItem->quantity_exactly;

            $qtyRejected = $statuses->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)->sum('quantity');

            $qtyTransferred = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)->sum('quantity');

            $editableQty = $qtyRejected + $qtyTransferred;

            $qtyToRemove = max(0, $oldQty - $newQty);

            if ($editableQty <= 0 && $qtyToRemove > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Aucune quantité disponible à réduire pour \"{$menu->name}\".",
                ], 422);
            }

            if ($qtyToRemove > 0 && $qtyToRemove > $editableQty) {

                \Log::warning('❌ BLOQUÉ - dépassement autorisé');

                return response()->json([
                    'status' => 'error',
                    'message' => "Impossible de supprimer {$qtyToRemove} \"{$menu->name}\". Maximum autorisé : {$editableQty}.",
                ], 422);
            }

            \Log::info('✅ PASSÉ');


            if ($existingItem->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {
                if ($m['quantity'] < $existingItem->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Réduction impossible : \"{$menu->name}\" est déjà servi. Vous ne pouvez qu'augmenter la quantité.",
                    ], 422);
                }
            }

        }
        return response()->json(['status' => 'success']);
    }

    public function checkStatusForDrinks(Request $request, string $uuid)
    {
        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'drinks' => ['required', 'array'],
            'drinks.*.product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
            'drinks.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated['drinks'] as $d) {

            $existingDrink = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)
                ->where('product_uuid', $d['product_uuid'])
                ->first();

            if (!$existingDrink) continue;

            $product = Product::findOrFail($d['product_uuid']);

            \Log::info('=== DEBUG REDUCTION DRINK ===');

            $statuses = OrderMenuItemStatusForDrink::where('order_restaurant_drink_uuid', $existingDrink->uuid)->get();

            $newQty = (int) $d['quantity'];
            $oldQty = (int) $existingDrink->quantity_exactly;

            $qtyRejected = $statuses->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)->sum('quantity');

            $qtyTransferred = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)->sum('quantity');

            $editableQty = $qtyRejected + $qtyTransferred;

            $qtyToRemove = max(0, $oldQty - $newQty);

            if ($editableQty <= 0 && $qtyToRemove > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Aucune quantité disponible à réduire pour \"{$product->name}\".",
                ], 422);
            }

            if ($qtyToRemove > 0 && $qtyToRemove > $editableQty) {

                \Log::warning('❌ BLOQUÉ DRINK - dépassement autorisé');

                return response()->json([
                    'status' => 'error',
                    'message' => "Impossible de supprimer {$qtyToRemove} \"{$product->name}\". Maximum autorisé : {$editableQty}.",
                ], 422);
            }

            \Log::info('✅ PASSÉ DRINK');


            // 🔴 Cas DELIVERED
            if ($existingDrink->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {
                if ($newQty < $existingDrink->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Réduction impossible : \"{$product->name}\" est déjà servi. Vous ne pouvez qu'augmenter la quantité.",
                    ], 422);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::addItemsToOrder
     * @permission_desc Editer la commande(Ajout , Suppression , Modification)
     */
    public function addItemsToOrder(Request $request, string $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'menus' => ['nullable', 'array'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:0'],
                'menus.*.unit_price' => ['nullable', 'numeric', 'min:0'],

                'drinks' => ['nullable', 'array'],
                'drinks.*.product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
                'drinks.*.quantity' => ['required', 'numeric', 'min:0'],
                'drinks.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $warehouseUuid = $warehouse->uuid;

            $warehouseDrinks = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $warehouseDrinkUuid = $warehouseDrinks->uuid;

            if (!$warehouseUuid) {
                throw new \Exception("Aucun entrepôt configuré");
            }

            if ($errors = $this->verifyMenuStock($validated['menus'], $warehouseUuid)) {
                return response()->json(['status'=>'error','message'=>'Stock insuffisant menus','details'=>$errors],422);
            }

            if (!empty($validated['drinks'])) {
                if ($errors = $this->verifyBarStock($validated['drinks'], $warehouseDrinkUuid)) {
                    return response()->json(['status'=>'error','message'=>'Stock boissons insuffisant','details'=>$errors],422);
                }
            }

            foreach ($validated['menus'] ?? [] as $m) {

                $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);
                $unitPrice = $m['unit_price'] ?? $menu->price ?? 0;
                $isLastItem = $m['is_last_items'] ?? false;

                if ($isLastItem) {
                    continue;
                }

                $existingItem = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->where('menus_restaurant_uuid', $menu->uuid)
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | 🔥 CAS 1 : ITEM EXISTE
                |--------------------------------------------------------------------------
                */
                if ($existingItem) {
                    $newQty = $m['quantity'];
                    $oldQty = $existingItem->quantity;

                    $isRejectedGroup = in_array($existingItem->status, [
                        OrderMenuRestaurantItemStatus::REJECTED->value,
                        OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                        OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
                        OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                    ]);

                    $isPartialCompletedOrReaday =  in_array($existingItem->status, [
                        OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                        OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value,
                    ]);

                    $isTransferred =  $existingItem->status === OrderMenuRestaurantItemStatus::TRANSFERRED->value;
                    $IsInpreparation  = $existingItem->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                    $statusesToTransfer = $existingItem->status === OrderMenuRestaurantItemStatus::DELIVERED->value;


                    // ⚡ Évite traitement inutile si quantité identique et pas de cas spéciaux
                    if ($newQty == $oldQty && !$isRejectedGroup && !$statusesToTransfer && !$isTransferred && !$IsInpreparation) {
                        continue;
                    }

                    if ($isRejectedGroup) {
                        $response = $this->handleRejected($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($isPartialCompletedOrReaday) {
                        $response = $this->handleQuantityUpdate($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($isTransferred) {
                        $response = $this->handleTransferred($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($IsInpreparation) {
                        $response = $this->handleInPreparation($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($statusesToTransfer) {
                        $response = $this->handleDeliveredOrPartial($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }


                    $this->updateExistingMenuItem($existingItem, $menu, $order, $newQty, $unitPrice, $auth,$warehouseUuid);
                    continue;
                }

                $this->createNewMenuItem($m, $menu, $order, $unitPrice, $auth);
            }



            foreach ($validated['drinks'] ?? [] as $d) {

                if ($d['is_last_items'] ?? false) continue;

                $unitPrice = $d['unit_price'] ?? 0;
                $product = Product::findOrFail($d['product_uuid']);

                $existingDrink = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)->where('product_uuid', $d['product_uuid'])->first();

                if ($existingDrink) {

                    $newQty = $d['quantity'];
                    $oldQty = $existingDrink->quantity;

                    $isRejectedGroupDrinks = in_array($existingDrink->status, [
                        OrderMenuRestaurantItemStatus::REJECTED->value,
                        OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                        OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
                        OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                    ]);

                    $isPartialCompletedOrReadayDrinks =  in_array($existingDrink->status, [
                        OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                        OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value,
                    ]);

                    $isTransferredDrinks =  $existingDrink->status === OrderMenuRestaurantItemStatus::TRANSFERRED->value;
                    $IsInpreparationDrinks  = $existingDrink->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                    $statusesToTransferDrinks = $existingDrink->status === OrderMenuRestaurantItemStatus::DELIVERED->value;

                    // ⚡ éviter traitement inutile
                    if ($newQty == $oldQty && !$isRejectedGroupDrinks && !$isPartialCompletedOrReadayDrinks && !$isTransferredDrinks && !$IsInpreparationDrinks && !$statusesToTransferDrinks) {
                        continue;
                    }

                    if ($isRejectedGroupDrinks) {
                        $response = $this->handleRejectedDrink($existingDrink, $d, $unitPrice, $auth, $order);
                        if ($response) return $response;
                        continue;
                    }

                    if ($isPartialCompletedOrReadayDrinks) {
                        $response = $this->handleQuantityUpdateDrink($existingDrink, $d, $unitPrice, $auth,$order);
                        if ($response) return $response;
                        continue;
                    }

                    if($isTransferredDrinks){
                        $response = $this->handleTransferredDrink($existingDrink, $d, $unitPrice, $auth,$order);
                        if ($response) return $response;
                        continue;
                    }

                    if($IsInpreparationDrinks){
                        $response = $this->handleInPreparationDrink($existingDrink, $d, $unitPrice, $auth,$order);
                        if ($response) return $response;
                        continue;
                    }

                    if($statusesToTransferDrinks){
                        $response = $this->handleDeliveredOrPartialDrink($existingDrink, $d, $unitPrice, $auth,$order);
                        if ($response) return $response;
                        continue;
                    }

                    $this->updateExistingDrink($existingDrink, $d, $product, $unitPrice, $auth,$warehouseDrinkUuid);
                    continue;
                }

                $this->createNewDrink($existingDrink, $d, $product, $unitPrice, $auth);
            }

            $this->refreshOrderStatus($order);

            // 🔹 Update order
            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'La commande a été mise à jour correctement'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();
            // 🔹 Loguer l'erreur complète dans le fichier de logs Laravel
            Log::error('Erreur lors de l’ajout des éléments à la commande', [
                'order_uuid' => $uuid,
                'user_id' => $auth->id ?? null,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 🔹 Retourner le message exact au frontend (optionnel, attention aux données sensibles)
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l’ajout des éléments : ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Helper central la gestion des boissons par statut
     */
    private function updateVirtualDrinkStock($order, $drink, $diffQuantity, $auth)
    {
        $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();

        $finalQuantity = (int) $drink->quantity;

        $product = Product::where('uuid', $drink->product_uuid)->first();
        if (!$product) {
            return;
        }

        // 🔥 1. SUPPRIMER UNIQUEMENT LES LIGNES "editing" DE CE PRODUIT
        DrinksVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
            ->where('product_uuid', $product->uuid)
            ->where('type', 'editing')
            ->delete();

        // 🔥 2. GESTION STOCK VIRTUEL RÉSERVÉ
        $virtualEntry = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
            ->where('item_uuid', $drink->uuid)
            ->where('item_type', 'drink')
            ->where('status', 'pending')
            ->where('product_uuid', $drink->product_uuid)
            ->first();

        if ($virtualEntry) {

            $virtualEntry->increment('quantity_reserved', $diffQuantity);
            $virtualEntry->increment('quantity_exactly', $diffQuantity);

        } else if ($diffQuantity > 0) {

            $this->reserveDrinkStock($order->uuid, $drink->uuid, $drink->product_uuid, $diffQuantity, $auth, $warehouse->uuid);
        }

        DrinksVirtualTemp::updateOrCreate(
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $product->uuid,
                'type' => 'initial'
            ],
            [
                'reservation_uuid' => $order->reservation_uuid,
                'quantity' => $finalQuantity,
                'quantity_used' => $finalQuantity,
                'status' => 'pending',
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }
    private function handleDeliveredOrPartialDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity;

        if ($newQty === $oldQty) {
            return null;
        }

        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$drink->product->name}\" déjà servi ou partiellement servi. Vous ne pouvez que augmenter la quantité.",
            ], 422);
        }

        $diff = $newQty - $oldQty;

        if ($diff < 0) {
            $newStatus = $this->resolveDrinkStatusFromStatuses($drink);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $drink->status;
        }

        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $drink->quantity_exactly + $diff,
            'total_price' => $unitPrice * $newQty,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);
        if ($diff !== 0) {
            $this->syncIncreasedStatusDrink($drink, $diff, $auth, $order);
            $this->updateVirtualDrinkStock($order, $drink, $diff, $auth);
        }
        return null;
    }
    private function syncIncreasedStatusDrink(OrderRestaurantDrink $drink, int $diff, $auth, OrderMenuRestaurant $order) {
        // 1️⃣ get or create TRANSFERRED
        $statusModel = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'order_restaurant_drink_uuid' => $drink->uuid,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        $statusModel->update([
            'quantity' => $statusModel->quantity + $diff,
            'quantity_accumulated' => $statusModel->quantity_accumulated + $diff,
            'updated_by' => $auth->id
        ]);

        // 2️⃣ clean stats
        StatisticsOrderStatusDrink::where('order_restaurant_drink_uuid', $drink->uuid)
            ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
            ->delete();

        // 3️⃣ recreate stats
        StatisticsOrderStatusDrink::create([
            'order_restaurant_drink_uuid' => $drink->uuid,
            'order_menu_restaurant_uuid' => $order->uuid,
            'product_uuid' => $drink->product_uuid,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity' => $drink->quantity_exactly,
            'transferred_at' => now(),
            'make_transferred_by' => $auth->id,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);
    }
    private function updateExistingDrink(OrderRestaurantDrink $drink, int $newQty , Product $product, float $unitPrice, $auth, string $warehouseDrinkUuid) {
        $oldQty = $drink->quantity;
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        $diffQty = $newQty - $oldQty;

        if ($diffQty !== 0) {
            if ($diffQty > 0) {
                $this->reserveDrinkStock($drink->order_menu_restaurant_uuid, $drink->uuid, $product->uuid, $diffQty, $auth, $warehouseDrinkUuid);
            }
            if ($diffQty < 0) {
                // réduction → libération stock virtuel
                \DB::table('drinks_virtuals_temp')
                    ->where('order_restaurant_drink_uuid', $drink->uuid)
                    ->where('product_uuid', $product->uuid)
                    ->decrement('quantity_used', abs($diffQty));
            }
        }
        return $drink;
    }
    private function createNewDrink(array $d, OrderMenuRestaurant $order, Product $product, float $unitPrice, $auth): OrderRestaurantDrink
    {
        $drink = OrderRestaurantDrink::create([
            'order_menu_restaurant_uuid' => $order->uuid,
            'product_uuid' => $product->uuid,
            'quantity' => $d['quantity'],
            'quantity_exactly' => $d['quantity'],
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $d['quantity'],
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_new_items' => true,
            'is_last_items' => false,
        ]);

        $this->updateVirtualDrinkStock($order, $drink, $d['quantity'], $auth);

        return $drink;
    }
    private function handleRejectedDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newQtyRequested = (int) $data['quantity'];
        $oldTotalQty = (int) $drink->quantity_exactly;
        $deliveredQty = (int) $drink->quantity_final_used;

        if ($newQtyRequested === $oldTotalQty) {
            $drink->update([
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'updated_by' => $auth->id,
            ]);
            return null;
        }

        $statuses = $drink->statuses;

        $qtyRejected = $statuses->whereIn('status', [
            OrderMenuRestaurantItemStatus::REJECTED->value,
            OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
        ])->sum('quantity');

        $qtyTransferred = $statuses
            ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
            ->sum('quantity');

        $totalMutable = $qtyRejected + $qtyTransferred;

        DB::transaction(function () use ($drink, $newQtyRequested, $oldTotalQty, $totalMutable, $auth, $order) {

            // 🔻 REDUCTION
            if ($newQtyRequested < $oldTotalQty) {

                $qtyToRemove = $oldTotalQty - $newQtyRequested;

                if ($qtyToRemove > $totalMutable) {
                    throw new \Exception(
                        "Action impossible. Vous ne pouvez réduire que {$totalMutable} quantité(s) rejetée(s) ou transférée(s)."
                    );
                }

                $this->removeQuantitiesDrink($drink, $qtyToRemove);
            }

            // 🔺 AUGMENTATION
            if ($newQtyRequested > $oldTotalQty) {

                $qtyToAdd = $newQtyRequested - $oldTotalQty;

                $this->incrementStatusWithHistoryDrink(
                    $drink,
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    $qtyToAdd,
                    $auth,
                    $order,
                    $newQtyRequested
                );
            }
        });

        $diff = $newQtyRequested - $oldTotalQty;
        if ($diff < 0) {
            $newStatus = $this->resolveDrinkStatusFromStatuses($drink);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = ($newQtyRequested === $deliveredQty) ? OrderMenuRestaurantItemStatus::DELIVERED->value : $drink->status;
        }

        $drink->update([
            'quantity' => $newQtyRequested,
            'quantity_exactly' => $newQtyRequested,
            'total_price' => $newQtyRequested * $unitPrice,
            'status' => $newStatus,
            'is_rejected' => false,
            'updated_by' => $auth->id,
        ]);

        // 🔥 STATS
        $quantityForStats = abs($diff);

        if ($diff === 0) {
            $quantityForStats = 0;
        }

        StatisticsOrderStatusDrink::updateOrCreate(
            [
                'order_restaurant_drink_uuid' => $drink->uuid,
                'status' => $newStatus
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => $quantityForStats,
                'rejected_at' => now(),
                'make_rejected_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff !== 0) {
            $this->updateVirtualDrinkStock($order, $drink, $diff, $auth);
        }

        return null;
    }
    private function incrementStatusWithHistoryDrink($drink, $status, $qty, $auth, $order, $newQtyExactly)
    {
        $statusModel = $drink->statuses()->firstOrCreate(
            [
                'status' => $status,
                'order_restaurant_drink_uuid' => $drink->uuid,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid, // 🔥 IMPORTANT
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
            ]
        );

        $statusModel->update([
            'quantity' => $statusModel->quantity + $qty,
            'quantity_accumulated' => $statusModel->quantity_accumulated + $qty,
            'quantity_exactly' => $newQtyExactly,
            'updated_by' => $auth->id,
        ]);
    }
    private function removeQuantitiesDrink(OrderRestaurantDrink $drink, int $qtyToRemove)
    {
        $remainingToRemove = $qtyToRemove;

        $reductionOrder = [
            OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            OrderMenuRestaurantItemStatus::REJECTED->value
        ];

        foreach ($reductionOrder as $statusType) {
            if ($remainingToRemove <= 0) break;

            $statusModel = $drink->statuses()
                ->where('status', $statusType)
                ->first();

            if ($statusModel && $statusModel->quantity > 0) {

                $take = min($remainingToRemove, (int)$statusModel->quantity);

                $statusModel->decrement('quantity', $take);
                $remainingToRemove -= $take;

                if ($statusModel->fresh()->quantity <= 0) {
                    $statusModel->update(['quantity_accumulated' => 0]);
                }
            }
        }
    }
    private function handleQuantityUpdateDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newTotalQty = (int) $data['quantity'];
        $oldTotalQty = (int) $drink->quantity_exactly;


        if ($newTotalQty === $oldTotalQty) {
            return null;
        }

        $diff = $newTotalQty - $oldTotalQty;

        // --- LOGIQUE DE RÉDUCTION ---
        if ($diff < 0) {
            $remainingToRemove = abs($diff);
            $statuses = $drink->statuses;

            // 1. Priorité aux REJECTED
            $rejectedStatuses = $statuses->whereIn('status', [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ]);

            foreach ($rejectedStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;
                if ($remainingToRemove <= 0) break;
            }

            // 2. Ensuite les TRANSFERRED (si encore des quantités à retirer)
            if ($remainingToRemove > 0) {
                $transferredStatuses = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value);
                foreach ($transferredStatuses as $status) {
                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->save();
                    $remainingToRemove -= $deduct;
                    if ($remainingToRemove <= 0) break;
                }
            }
        }

        $status = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'order_restaurant_drink_uuid' => $drink->uuid,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
            ]
        );

        // Si augmentation, on ajoute au stock transféré
        if ($diff > 0) {
            $status->quantity += $diff;
        }

        // On synchronise avec la nouvelle valeur envoyée par l'utilisateur
        $status->quantity_accumulated = $newTotalQty;
        $status->quantity_exactly = $newTotalQty;
        $status->updated_by = $auth->id;
        $status->save();


        if ($diff < 0) {
            $newStatus = $this->resolveDrinkStatusFromStatuses($drink);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $drink->status;
        }

        $drink->update([
            'quantity'         => $newTotalQty,
            'quantity_exactly' => $newTotalQty,
            'total_price'      => $unitPrice * $newTotalQty,
            'status'           => $newStatus,
            'updated_by'       => $auth->id,
        ]);

        StatisticsOrderStatusDrink::where('order_restaurant_drink_uuid', $drink->uuid)
            ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
            ->delete();

        StatisticsOrderStatusDrink::create([
            'order_restaurant_drink_uuid' => $drink->uuid,
            'order_menu_restaurant_uuid' => $order->uuid,
            'product_uuid' => $drink->product_uuid,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity' => $newTotalQty,
            'transferred_at' => now(),
            'make_transferred_by' => $auth->id,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);

        if ($diff !== 0) {
            $this->updateVirtualDrinkStock($order, $drink, $diff, $auth);
        }

        return null;
    }
    private function handleInPreparationDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity_exactly;

        if ($newQty === $oldQty) {
            return null;
        }

        $diff = $newQty - $oldQty;

        // ✅ LOGIQUE DE RÉDUCTION (Même logique que pour handleTransferred/QuantityUpdate)
        if ($diff < 0) {
            $remainingToRemove = abs($diff);
            $statuses = $drink->statuses;

            // 1. On tape d'abord dans les REJECTED
            $rejectedStatuses = $statuses->whereIn('status', [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ]);

            foreach ($rejectedStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;
                if ($remainingToRemove <= 0) break;
            }

            // 2. Si pas assez, on tape dans les TRANSFERRED
            if ($remainingToRemove > 0) {
                $transferredStatuses = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value);
                foreach ($transferredStatuses as $status) {
                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->save();
                    $remainingToRemove -= $deduct;
                    if ($remainingToRemove <= 0) break;
                }
            }
        }

        $transferredStatus = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'order_restaurant_drink_uuid' => $drink->uuid,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff > 0) {
            $transferredStatus->quantity += $diff;
        }

        $transferredStatus->quantity_exactly = $newQty;
        $transferredStatus->quantity_accumulated = $newQty;
        $transferredStatus->updated_by = $auth->id;
        $transferredStatus->save();

        if ($diff < 0) {
            $newStatus = $this->resolveDrinkStatusFromStatuses($drink);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $drink->status;
        }

        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);

        // 📊 STATS DRINKS
        StatisticsOrderStatusDrink::updateOrCreate(
            [
                'order_restaurant_drink_uuid' => $drink->uuid,
                'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => $transferredStatus->quantity,
                'in_preparation_at' => now(),
                'make_in_preparation_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff !== 0) {
            $this->updateVirtualDrinkStock($order, $drink, $diff, $auth);
        }

        return null;
    }
    private function handleTransferredDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity;

        if ($newQty === $oldQty) {
            return null;
        }

        $qtyToRemove = $oldQty - $newQty;
        $statuses = $drink->statuses;

        // Récupération des deux groupes
        $rejectedStatuses = $statuses->whereIn('status', [
            OrderMenuRestaurantItemStatus::REJECTED->value,
            OrderMenuRestaurantItemStatus::NEW_REJECTED->value
        ]);
        $transferredStatuses = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value);

        $qtyRejected = $rejectedStatuses->sum('quantity');
        $qtyTransferred = $transferredStatuses->sum('quantity');
        $qtyAvailableToRemove = $qtyRejected + $qtyTransferred;

        if ($qtyToRemove > 0 && $qtyToRemove > $qtyAvailableToRemove) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer {$qtyToRemove} \"{$drink->product->name}\". Maximum autorisé : {$qtyAvailableToRemove} (rejetées + transférées).",
            ], 422);
        }

        $remainingToRemove = $qtyToRemove;

        // ✅ 1. D'ABORD : Déduire des quantités REJECTED
        if ($remainingToRemove > 0 && $qtyRejected > 0) {
            foreach ($rejectedStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;

                if ($remainingToRemove <= 0) break;
            }
        }

        // ✅ 2. ENSUITE : Déduire des quantités TRANSFERRED (si nécessaire)
        if ($remainingToRemove > 0 && $qtyTransferred > 0) {
            foreach ($transferredStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;

                if ($remainingToRemove <= 0) break;
            }
        }

        // 🔹 TRANSFERRED principal
        $status = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'order_restaurant_drink_uuid' => $drink->uuid,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        $diff = $newQty - $oldQty;

        // Si augmentation, on ajoute au stock transféré
        if ($diff > 0) {
            $status->quantity += $diff;
        }

        // Mise à jour des compteurs totaux
        $status->quantity_accumulated = $newQty;
        $status->quantity_exactly = $newQty;
        $status->updated_by = $auth->id;
        $status->save();

        if ($diff < 0) {
            $newStatus = $this->resolveDrinkStatusFromStatuses($drink);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $drink->status;
        }

        // ✅ MISE À JOUR DE L'ITEM PARENT
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);

        // 📊 STATS DRINKS
        StatisticsOrderStatusDrink::updateOrCreate(
            [
                'order_restaurant_drink_uuid' => $drink->uuid,
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => $newQty,
                'transferred_at' => now(),
                'make_transferred_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff !== 0) {
            $this->updateVirtualDrinkStock($order, $drink, $diff, $auth);
        }

        return null;
    }


    /**
     * Helper central pour ajouter de la quantité à un statut en gérant le cumul historique
     */
    private function resolveDrinkStatusFromStatuses(OrderRestaurantDrink $item): string
    {
        $statuses = $item->statuses()->pluck('quantity', 'status')->toArray();
        if (empty($statuses)) {
            return OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }
        foreach (OrderMenuRestaurantItemStatus::priorityList() as $status) {
            if (($statuses[$status] ?? 0) > 0) {
                return $status;
            }
        }
        arsort($statuses);
        return array_key_first($statuses)
            ?? OrderMenuRestaurantItemStatus::TRANSFERRED->value;
    }

    private function resolveItemStatusFromStatuses(OrderMenuRestaurantItem $item): string
    {
        $statuses = $item->statuses()->pluck('quantity', 'status')->toArray();

        if (!$statuses) {
            return OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }
        $maxQty = max($statuses);

        $candidates = array_keys(array_filter($statuses, fn($qty) => $qty === $maxQty));
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        foreach (OrderMenuRestaurantItemStatus::priorityList() as $priorityStatus) {
            if (in_array($priorityStatus, $candidates, true)) {
                return $priorityStatus;
            }
        }
        return $candidates[0];
    }
    private function updateExistingMenuItem(OrderMenuRestaurantItem $item, MenuRestaurant $menu, OrderMenuRestaurant $order, int $newQty, float $unitPrice, $auth, $warehouseUuid) {
        $oldQty = $item->quantity;
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 différence
        $diffQty = $newQty - $oldQty;

        if ($diffQty !== 0) {
            $components = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
            foreach ($components as $comp) {
                $qty = $diffQty * $comp->quantity_used;

                $this->reserveStock($order->uuid, $item->uuid, 'menu', $comp->product_uuid, $qty, $auth, $warehouseUuid);
            }
        }

        return $item;
    }
    private function createNewMenuItem(array $m, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth)
    {
        $item = OrderMenuRestaurantItem::create([
            'order_menu_restaurant_uuid' => $order->uuid,
            'menus_restaurant_uuid' => $menu->uuid,
            'quantity' => $m['quantity'],
            'quantity_exactly' => $m['quantity'],
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $m['quantity'],
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_new_items' => true,
        ]);

        $this->updateVirtualStock($menu, $order, $item, $m['quantity'], $auth);

        return $item;
    }
    private function updateVirtualStock($menu, $order, $item, $diffQuantity, $auth)
    {
        $components = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
        $warehouse = Warehouse::where('is_used_for_restaurant', true)->first();

        $finalQuantity = (int) $item->quantity;


        MenuVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
            ->where('type', 'editing')
            ->where('menus_restaurant_uuid', $menu->uuid)
            ->delete();

        foreach ($components as $comp) {

            $qtyDelta = $diffQuantity * $comp->quantity_used;
            $totalQtyUsed = $finalQuantity * $comp->quantity_used;

            $virtualEntry = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
                ->where('item_uuid', $item->uuid)
                ->where('status', 'pending')
                ->where('product_uuid', $comp->product_uuid)
                ->first();

            if ($virtualEntry) {
                $virtualEntry->increment('quantity_reserved', $qtyDelta);
                $virtualEntry->increment('quantity_exactly', $qtyDelta);
            } else if ($qtyDelta > 0) {
                $this->reserveStock(
                    $order->uuid,
                    $item->uuid,
                    'menu',
                    $comp->product_uuid,
                    $qtyDelta,
                    $auth,
                    $warehouse->uuid
                );
            }

            MenuVirtualTemp::updateOrCreate(
                [
                    'menus_restaurant_uuid' => $menu->uuid,
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'product_uuid' => $comp->product_uuid,
                    'type' => 'initial'
                ],
                [
                    'quantity' => $finalQuantity,
                    'quantity_used' => $totalQtyUsed,
                    'status' => 'pending',
                    'is_not_used_stock' => false,
                    'updated_by' => $auth->id,
                    'created_by' => $auth->id,
                ]
            );
        }
    }
    private function handleRejected(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice,$auth) {
        $newQtyRequested = (int) $data['quantity'];
        $oldTotalQty = (int) $item->quantity_exactly;
        $deliveredQty = (int) $item->quantity_final_used;

        if ($newQtyRequested === $oldTotalQty) {
            $item->update([
                'status' => $this->resolveItemStatusFromStatuses($item),
                'updated_by' => $auth->id,
            ]);
            return null;
        }

        $statuses = $item->statuses;

        $qtyRejected = $statuses->whereIn('status', [
            OrderMenuRestaurantItemStatus::REJECTED->value,
            OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
        ])->sum('quantity');

        $qtyTransferred = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)->sum('quantity');

        $totalMutable = $qtyRejected + $qtyTransferred;

        DB::transaction(function () use ($item, $newQtyRequested, $oldTotalQty, $totalMutable, $auth, $order, $menu) {

            if ($newQtyRequested < $oldTotalQty) {

                $qtyToRemove = $oldTotalQty - $newQtyRequested;

                if ($qtyToRemove > $totalMutable) {
                    throw new \Exception(
                        "Action impossible. Vous ne pouvez réduire que {$totalMutable} quantité(s) rejetée(s) ou transférée(s)."
                    );
                }

                $this->removeQuantities($item, $qtyToRemove);
            }

            if ($newQtyRequested > $oldTotalQty) {

                $qtyToAdd = $newQtyRequested - $oldTotalQty;

                $this->incrementStatusWithHistory($item, OrderMenuRestaurantItemStatus::TRANSFERRED->value, $qtyToAdd, $auth, $order, $newQtyRequested);
            }
        });
        $diff = $newQtyRequested - $oldTotalQty;
        if ($diff < 0) {
            $newStatus = $this->resolveItemStatusFromStatuses($item);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }
        if ($newQtyRequested === $deliveredQty) {
            $newStatus = OrderMenuRestaurantItemStatus::DELIVERED->value;
        }
        $item->update([
            'quantity' => $newQtyRequested,
            'quantity_exactly' => $newQtyRequested,
            'total_price' => $newQtyRequested * $unitPrice,
            'status' => $newStatus,
            'is_rejected' => false,
            'updated_by' => $auth->id,
        ]);

        /**
         * =========================
         * 🔥 STATS (CORRIGÉES)
         * =========================
         */
        $quantityForStats = abs($diff); // ✔ SIMPLE + CLEAN

        if ($diff === 0) {
            $quantityForStats = 0;
        }

        /**
         * =========================
         * 🔥 SAVE STATS
         * =========================
         */
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => $newStatus
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $quantityForStats,
                'rejected_at' => now(),
                'make_rejected_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }

        return null;
    }
    private function incrementStatusWithHistory($item, $status, $qty, $auth, $order, $newQtyExactly)
    {
        $statusModel = $item->statuses()->firstOrCreate(
            ['status' => $status],
            [
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid,
            ]
        );

        $statusModel->update([
            'quantity' => $statusModel->quantity + $qty,
            'quantity_accumulated' => $statusModel->quantity_accumulated + $qty,
            'quantity_exactly' => $newQtyExactly,
            'updated_by' => $auth->id,
        ]);
    }
    private function removeQuantities(OrderMenuRestaurantItem $item, int $qtyToRemove)
    {
        $remainingToRemove = $qtyToRemove;
        $reductionOrder = [
            OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            OrderMenuRestaurantItemStatus::REJECTED->value
        ];

        foreach ($reductionOrder as $statusType) {
            if ($remainingToRemove <= 0) break;

            $statusModel = $item->statuses()->where('status', $statusType)->first();

            if ($statusModel && $statusModel->quantity > 0) {
                $take = min($remainingToRemove, (int)$statusModel->quantity);

                $statusModel->decrement('quantity', $take);
                $remainingToRemove -= $take;
                if ($statusModel->fresh()->quantity <= 0) {
                    $statusModel->update(['quantity_accumulated' => 0]);
                }
            }
        }
    }
    private function handleDeliveredOrPartial(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice,$auth) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $item->quantity_exactly;

        Log::info('Quantity debug', [
            'newQty' => $newQty,
            'oldQty' => $oldQty,
        ]);

        if ($newQty === $oldQty) {
            return null;
        }

        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$menu->name}\" déjà servie. Vous ne pouvez que augmenter la quantité.",
            ], 422);
        }

        $diff = $newQty - $oldQty;
        if ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }
        $item->update([
            'quantity' => $diff,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);
        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
            $this->syncIncreasedStatus($item, $diff, $auth,$order);
        }

        return null;
    }
    private function syncIncreasedStatus(OrderMenuRestaurantItem $item, int $diff, $auth, OrderMenuRestaurant $order)
    {
        // 1️⃣ Récupération ou création du statut TRANSFERRED
        $statusModel = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value],
            [
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'created_by' => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid,
            ]
        );
        $statusModel->update([
            'quantity'             => $statusModel->quantity + $diff,
            'quantity_accumulated' => $statusModel->quantity_accumulated + $diff,
            'updated_by'           => $auth->id
        ]);

        StatisticsOrderStatusMenuRestaurant::where('order_menu_restaurant_item_uuid', $item->uuid)
            ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
            ->delete();

        StatisticsOrderStatusMenuRestaurant::create([
            'order_menu_restaurant_item_uuid' => $item->uuid,
            'order_menu_restaurant_uuid'      => $order->uuid,
            'status'                          => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity'                        => $item->quantity_exactly,
            'transferred_at'                  => now(),
            'make_transferred_by'             => $auth->id,
            'created_by'                       => $auth->id,
            'updated_by'                       => $auth->id,
        ]);
    }
    private function handleInPreparation(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $item->quantity_exactly;

        if ($newQty === $oldQty) {
            return null;
        }

        $diff = $newQty - $oldQty;

        // ✅ LOGIQUE DE RÉDUCTION (Même logique que pour handleTransferred/QuantityUpdate)
        if ($diff < 0) {
            $remainingToRemove = abs($diff);
            $statuses = $item->statuses;

            // 1. On tape d'abord dans les REJECTED
            $rejectedStatuses = $statuses->whereIn('status', [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ]);

            foreach ($rejectedStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;
                if ($remainingToRemove <= 0) break;
            }

            // 2. Si pas assez, on tape dans les TRANSFERRED
            if ($remainingToRemove > 0) {
                $transferredStatuses = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value);
                foreach ($transferredStatuses as $status) {
                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->save();
                    $remainingToRemove -= $deduct;
                    if ($remainingToRemove <= 0) break;
                }
            }
        }

        // ✅ MISE À JOUR DU STATUT TRANSFERRED PRINCIPAL
        $transferredStatus = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff > 0) {
            $transferredStatus->quantity += $diff;
        }

        // On synchronise avec la nouvelle valeur totale demandée
        $transferredStatus->quantity_exactly = $newQty;
        $transferredStatus->quantity_accumulated = $newQty;
        $transferredStatus->updated_by = $auth->id;
        $transferredStatus->save();

        if ($diff < 0) {
            $newStatus = $this->resolveItemStatusFromStatuses($item);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $item->status;
        }

        // ✅ MISE À JOUR DE L'ITEM PARENT
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);

        // ✅ STATISTIQUES (Mise à jour pour IN_PREPARATION)
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $newQty,
                'in_preparation_at' => now(),
                'make_in_preparation_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }

        return null;
    }
    private function handleTransferred(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $item->quantity;

        if ($newQty === $oldQty) {
            return null;
        }

        $qtyToRemove = $oldQty - $newQty;
        $statuses = $item->statuses;

        // Récupération des deux groupes
        $rejectedStatuses = $statuses->whereIn('status', [
            OrderMenuRestaurantItemStatus::REJECTED->value,
            OrderMenuRestaurantItemStatus::NEW_REJECTED->value
        ]);
        $transferredStatuses = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value);

        $qtyRejected = $rejectedStatuses->sum('quantity');
        $qtyTransferred = $transferredStatuses->sum('quantity');
        $qtyAvailableToRemove = $qtyRejected + $qtyTransferred;

        if ($qtyToRemove > 0 && $qtyToRemove > $qtyAvailableToRemove) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer {$qtyToRemove} \"{$menu->name}\". Maximum autorisé : {$qtyAvailableToRemove} (rejetées + transférées).",
            ], 422);
        }

        $remainingToRemove = $qtyToRemove;

        // ✅ 1. D'ABORD : Déduire des quantités REJECTED
        if ($remainingToRemove > 0 && $qtyRejected > 0) {
            foreach ($rejectedStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;

                if ($remainingToRemove <= 0) break;
            }
        }

        // ✅ 2. ENSUITE : Déduire des quantités TRANSFERRED (si nécessaire)
        if ($remainingToRemove > 0 && $qtyTransferred > 0) {
            foreach ($transferredStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;

                if ($remainingToRemove <= 0) break;
            }
        }

        // 🔹 Gérer le statut TRANSFERRED principal (création ou mise à jour)
        $status = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value],
            [
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        $diff = $newQty - $oldQty;

        // Si augmentation, on ajoute au stock transféré
        if ($diff > 0) {
            $status->quantity += $diff;
        }

        // Mise à jour des compteurs totaux
        $status->quantity_accumulated = $newQty;
        $status->quantity_exactly = $newQty;
        $status->updated_by = $auth->id;
        $status->save();

        // 📊 Mise à jour des statistiques
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $newQty,
                'transferred_at' => now(),
                'make_transferred_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
        if ($diff < 0) {
            $newStatus = $this->resolveItemStatusFromStatuses($item);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $item->status;
        }
        // 🔹 Update de l'item parent
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
            'status' => $newStatus,
        ]);

        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }

        return null;
    }
    private function handleQuantityUpdate(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth)
    {
        $newTotalQty = (int) $data['quantity'];
        $oldTotalQty = (int) $item->quantity_exactly;

        if ($newTotalQty === $oldTotalQty) {
            return null;
        }

        $diff = $newTotalQty - $oldTotalQty;

        // --- LOGIQUE DE RÉDUCTION ---
        if ($diff < 0) {
            $remainingToRemove = abs($diff);
            $statuses = $item->statuses;

            // 1. Priorité aux REJECTED
            $rejectedStatuses = $statuses->whereIn('status', [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ]);

            foreach ($rejectedStatuses as $status) {
                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->save();
                $remainingToRemove -= $deduct;
                if ($remainingToRemove <= 0) break;
            }

            // 2. Ensuite les TRANSFERRED (si encore des quantités à retirer)
            if ($remainingToRemove > 0) {
                $transferredStatuses = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value);
                foreach ($transferredStatuses as $status) {
                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->save();
                    $remainingToRemove -= $deduct;
                    if ($remainingToRemove <= 0) break;
                }
            }
        }

        // --- MISE À JOUR DU STATUT TRANSFERRED PRINCIPAL ---
        $status = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value],
            [
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid
            ]
        );

        if ($diff > 0) {
            $status->quantity += $diff;
        }

        // On synchronise avec la nouvelle valeur envoyée par l'utilisateur
        $status->quantity_accumulated = $newTotalQty;
        $status->quantity_exactly = $newTotalQty;
        $status->updated_by = $auth->id;
        $status->save();

        if ($diff < 0) {
            $newStatus = $this->resolveItemStatusFromStatuses($item);
        } elseif ($diff > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        } else {
            $newStatus = $item->status;
        }
        $item->update([
            'quantity'         => $newTotalQty,
            'quantity_exactly' => $newTotalQty,
            'total_price'      => $unitPrice * $newTotalQty,
            'updated_by'       => $auth->id,
            'status' => $newStatus,
        ]);

        // Refresh Statistiques
        StatisticsOrderStatusMenuRestaurant::where('order_menu_restaurant_item_uuid', $item->uuid)
            ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
            ->delete();

        StatisticsOrderStatusMenuRestaurant::create([
            'order_menu_restaurant_item_uuid' => $item->uuid,
            'order_menu_restaurant_uuid' => $order->uuid,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity' => $newTotalQty,
            'transferred_at' => now(),
            'make_transferred_by' => $auth->id,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);

        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }

        return null;
    }

    public function verify_to_delete_items_menu(Request $request, $order_uuid, $item_uuid)
    {
        DB::beginTransaction();

        try {
            $order = OrderMenuRestaurant::where('uuid', $order_uuid)->firstOrFail();

            $item = OrderMenuRestaurantItem::where('menus_restaurant_uuid', $item_uuid)->where('order_menu_restaurant_uuid', $order->uuid)
                ->with(['virtuals', 'statuses'])->first();

            if (!$item) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Item introuvable.'
                ], 404);
            }

            // 🔴 Déjà servi ?
            if ($item->quantity_final_used > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Suppression impossible : une quantité a déjà été servie.'
                ], 403);
            }

            $statuses = $item->statuses->pluck('quantity', 'status');
            $totalQty = array_sum($statuses->toArray());
            $rejectedQty = $statuses[OrderMenuRestaurantItemStatus::REJECTED->value] ?? 0;
            $transferredQty = $statuses[OrderMenuRestaurantItemStatus::TRANSFERRED->value] ?? 0;

            $allowedQty = $rejectedQty + $transferredQty;

            if ($allowedQty !== $totalQty) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Suppression impossible : certaines quantités sont encore actives.'
                ], 403);
            }

            $item->virtuals()->delete();

            MenuVirtualTemp::where('menus_restaurant_uuid', $item_uuid)->where('order_menu_restaurant_uuid', $order_uuid)
                ->delete();
            $item->statuses()->delete();
            $item->statistics()->delete();
            $item->delete();

            $remainingItems = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->count();

            if ($remainingItems === 0) {
                VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)->delete();
                $order->delete();
                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Item supprimé et commande supprimée (dernier élément).'
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Item supprimé avec succès.'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            \Log::error('Delete item error', [
                'message' => $e->getMessage(),
                'order_uuid' => $order_uuid,
                'item_uuid' => $item_uuid
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verify_to_delete_items_drink(Request $request, $order_uuid, $drink_uuid)
    {
        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::where('uuid', $order_uuid)->firstOrFail();

            $drink = OrderRestaurantDrink::where('product_uuid', $drink_uuid)
                ->where('order_menu_restaurant_uuid', $order->uuid)
                ->with('product')
                ->first();

            if (!$drink) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Boisson introuvable.'
                ], 404);
            }

            if ($drink->quantity_final_used > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'La suppression est impossible car une quantité a déjà été servie.'
                ], 403);
            }

            $allowedStatuses = [
                OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::TRANSFERRED->value
            ];

            if (!in_array($drink->status, $allowedStatuses)) {

                return response()->json([
                    'status' => 'error',
                    'message' => "La suppression est impossible car le statut actuel est : "
                        . OrderMenuRestaurantItemStatus::safeLabel($drink->status) . "."
                ], 403);
            }

            // 🔥 suppression
            $drink->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Boisson supprimée avec succès.'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::index
     * @permission_desc Afficher la liste des commandes
     */
    public function index(Request $request)
    {
        $auth = auth()->user();

        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $roleIds = $auth->roles->pluck('id');
        $start_date = Carbon::parse($request->input('start_date'))->startOfDay();
        $end_date = Carbon::parse($request->input('end_date'))->endOfDay();


        $query = OrderMenuRestaurant::with([
            'restaurantTable',
            'creator',
            'updater',
            'validator',
            'cancelor',
            'partners_restaurant',
            'warehouse',
            'restaurant_room',
            'menu_restaurant',
            'items.menu',
            'drinks.product',
            'free_client_for_restaurant'
        ]);

        if ($request->filled('restaurant_table_uuid')) {
            $query->where('restaurant_table_uuid', $request->restaurant_table_uuid);
        }
        if ($request->filled('order_menu_restaurant_date')) {
            $query->where('order_menu_restaurant_date', $request->order_menu_restaurant_date);
        }
        if ($request->filled('menu_restaurant_uuid')) {
            $query->where('menu_restaurant_uuid', $request->menu_restaurant_uuid);
        }
        if ($request->filled('restaurant_room_uuid')) {
            $query->where('restaurant_room_uuid', $request->restaurant_room_uuid);
        }
        if ($request->filled('partners_restaurant_uuid')) {
            $query->where('partners_restaurant_uuid', $request->partners_restaurant_uuid);
        }

        if ($request->filled('consumption_type')) {
            $query->where('consumption_type', $request->consumption_type);
        }

        if ($request->filled('type_clients_for_payment')) {
            $query->where('type_clients_for_payment', $request->type_clients_for_payment);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start_date = Carbon::parse($request->start_date)->startOfDay();
            $end_date = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start_date, $end_date]);
        }

        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_orders_for_restaurant')) {

            $roleIds = $auth->roles->pluck('id');

            $query->where(function ($q) use ($auth, $roleIds) {

                // 🔹 Utilisateurs avec la permission view_role_related_data
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }

                // 🔹 Utilisateurs avec la permission view_transferred_orders
                if ($auth->can('view_transferred_orders_for_restaurant')) {
                    $q->orWhereNotNull('received_by');
                }

                // 🔹 Utilisateurs sans aucune de ces permissions : seulement leurs propres commandes
                if (!$auth->can('view_role_related_data') && !$auth->can('view_transferred_orders_for_restaurant')) {
                    $q->orWhere('created_by', $auth->id);
                }

            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('unit_price', 'like', "%{$search}%")
                    ->orWhere('total_price', 'like', "%{$search}%")
                    ->orWhere('is_for_sale_free', 'like', "%{$search}%")
                    ->orWhere('consumption_type', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reason_cancel', 'like', "%{$search}%")
                    ->orWhere('validated_at', 'like', "%{$search}%")
                    ->orWhere('cancelled_at', 'like', "%{$search}%")
                    ->orWhere('restaurant_table_uuid', 'like', "%{$search}%")
                    ->orWhere('created_by', 'like', "%{$search}%")
                    ->orWhere('updated_by', 'like', "%{$search}%")
                    ->orWhere('validated_by', 'like', "%{$search}%")
                    ->orWhere('cancelled_by', 'like', "%{$search}%")
                    ->orWhere('type_clients_for_payment', 'like', "%{$search}%")
                    ->orWhere('order_menu_restaurant_date', 'like', "%{$search}%")
                    ->orWhere('remise', 'like', "%{$search}%")
                    ->orWhere('partners_restaurant_uuid', 'like', "%{$search}%")
                    ->orWhere('warehouse_uuid', 'like', "%{$search}%")
                    ->orWhere('restaurant_room_uuid', 'like', "%{$search}%")
                    ->orWhere('menu_restaurant_uuid', 'like', "%{$search}%")
                    ->orWhere('quantity', 'like', "%{$search}%")


                    ->orWhereHas('warehouse', function ($qw) use ($search) {
                        $qw->where('ref', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('stock_type', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })

                    ->orWhereHas('restaurantTable', function ($rt) use ($search) {
                        $rt->where('uuid', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('table_number', 'like', "%{$search}%")
                            ->orWhere('capacity', 'like', "%{$search}%");
                    })

                    ->orWhereHas('partners_restaurant', function ($pr) use ($search) {
                        $columns = ['uuid', 'code', 'first_name', 'last_name', 'full_name', 'email', 'phone_number',
                            'second_phone_number', 'address', 'description', 'cni_number',];
                        foreach ($columns as $column) {
                            $pr->orWhere($column, 'like', "%{$search}%");
                        }
                    })

                    ->orWhereHas('restaurant_room', function ($rr) use ($search) {
                        $rr->where('uuid', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('rooms_number', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('type', 'like', "%{$search}%")
                            ->orWhere('capacity', 'like', "%{$search}%");
                    })

                    ->orWhereHas('menu_restaurant', function ($mr) use ($search) {
                        $mr->where('uuid', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    })

                    ->orWhereHas('free_client_for_restaurant', function ($fr) use ($search) {
                        $fr->where('uuid', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    })

                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })

                    ->orWhereHas('updater', function ($qu) use ($search) {
                        $qu->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })

                    ->orWhereHas('validator', function ($qv) use ($search) {
                        $qv->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
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
     * @permission OrderMenuRestaurantController::show
     * @permission_desc Afficher les détails d'une commande
     */
    public function show($uuid)
    {
        try {
            $OrderMenu = OrderMenuRestaurant::with([
                'restaurantTable',
                'creator',
                'updater',
                'validator',
                'cancelor',
                'partners_restaurant',
                'warehouse',
                'restaurant_room',
                'menu_restaurant',
                'free_client_for_restaurant',
                'items.rejector',
                'drinks.rejector',
                'drinks.statuses',
                'items.statuses',

                'items' => function ($query) {
                    $query->orderByDesc('created_at');
                },
                'items.menu',
                               'drinks' => function ($query) {
                    $query->orderByDesc('created_at');
                },
                'drinks.product',
                'items.virtuals.product'
            ])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => "Détails de la commande '{$OrderMenu->code}' récupérés avec succès.",
                'data' => $OrderMenu
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
     * @permission OrderMenuRestaurantController::rejectMenuItems
     * @permission_desc Rejetter les plats selectionnées d'une commande
     */
    public function rejectMenuItems(Request $request, $uuid)
    {
        $auth = auth()->user();

        $validatedItems = $request->validate([
            '*.item_uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
            '*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with(['items.statuses'])
                ->firstOrFail();

            foreach ($validatedItems as $selection) {
                $item = $order->items->where('uuid', $selection['item_uuid'])->first();
                if (!$item) continue;

                $qtyToReject = (int) $selection['quantity_to_deliver'];
                $originalQtyToReject = $qtyToReject;

                // 1. Déduction en cascade : d'abord TRANSFERRED, puis IN_PREPARATION
                $this->deductFromStatus($item, OrderMenuRestaurantItemStatus::TRANSFERRED->value, $qtyToReject);
                if ($qtyToReject > 0) {
                    $this->deductFromStatus($item, OrderMenuRestaurantItemStatus::IN_PREPARATION->value, $qtyToReject);
                }

                // 2. Enregistrement du rejet dans la table des statuts
                $rejectedStatus = $item->statuses()->firstOrCreate(
                    ['status' => OrderMenuRestaurantItemStatus::REJECTED->value],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'quantity'             => 0,
                        'quantity_accumulated' => 0,
                        'created_by'           => $auth->id
                    ]
                );

                $rejectedStatus->increment('quantity', $originalQtyToReject);
                $rejectedStatus->increment('quantity_accumulated', $originalQtyToReject);
                $rejectedStatus->updated_by = $auth->id;
                $rejectedStatus->save();

                StatisticsOrderStatusMenuRestaurant::updateOrCreate(
                    [
                        'order_menu_restaurant_item_uuid' => $item->uuid,
                        'status' => OrderMenuRestaurantItemStatus::REJECTED->value,
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'quantity' => $rejectedStatus->quantity,
                        'rejected_at' => now(),
                        'make_rejected_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'created_by' => $auth->id,
                    ]
                );
                // Mise à jour de l'item principal
                $item->update([
                    'is_rejected' => true,
                    'rejected_by' => $auth->id,
                    'rejected_at' => now(),
                    'status'      => OrderMenuRestaurantItemStatus::REJECTED->value,
                ]);
            }

            $this->refreshOrderStatus($order);
            $order->update(['updated_by' => $auth->id]);

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Rejet traité avec succès.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function deductFromStatus($item, $statusValue, &$qtyToReject)
    {
        $statusRow = $item->statuses()->where('status', $statusValue)->first();
        if ($statusRow && $statusRow->quantity > 0) {
            $deductible = min($qtyToReject, $statusRow->quantity);

            $statusRow->decrement('quantity', $deductible);
            $statusRow->decrement('quantity_accumulated', $deductible);

            $qtyToReject -= $deductible;

            // Optionnel : supprimer la ligne si quantité = 0
            if ($statusRow->fresh()->quantity <= 0) {
                $statusRow->delete();
            }
        }
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::getItemsStatuses
     * @permission_desc Afficher l'historique des statuts d’un item de commande
     */
    public function getItemsStatuses(Request $request, $orderUuid)
    {
        $itemUuids = $request->query('items', []);
        if (is_string($itemUuids)) $itemUuids = explode(',', $itemUuids);
        if (empty($itemUuids)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucun item spécifié'
            ], 422);
        }

        $items = OrderMenuRestaurantItem::with('statuses', 'menu')
            ->where('order_menu_restaurant_uuid', $orderUuid)
            ->whereIn('uuid', $itemUuids)
            ->get();

        $result = [];

        foreach ($items as $item) {
            $statuses = [];

            foreach ($item->statuses as $status) {
                $key = $status->status; // clé brute, ex: "rejected"
                $label = OrderMenuRestaurantItemStatus::safeLabel($status->status); // label FR

                if (!isset($statuses[$key])) {
                    $statuses[$key] = [
                        'quantity' => 0,
                        'exactly' => 0,
                        'label' => $label, // ajoute le label français
                    ];
                }

                $statuses[$key]['quantity'] += $status->quantity;
                $statuses[$key]['exactly'] += $status->quantity_exactly;
            }

            $result[$item->uuid] = [
                'item_uuid' => $item->uuid,
                'name' => $item->menu->name ?? $item->name,
                'statuses' => $statuses,
            ];
        }

        return response()->json([
            'status' => 'success',
            'items' => $result
        ]);
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::rejectDrinks
     * @permission_desc Rejetter les boissons selectionnées d'une commande
     */
    public function rejectDrinks(Request $request, $uuid)
    {
        $auth = auth()->user();

        $validatedItems = $request->validate([
            '*.drink_uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
            '*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with(['drinks.statuses'])
                ->firstOrFail();

            foreach ($validatedItems as $selection) {

                $drink = $order->drinks->where('uuid', $selection['drink_uuid'])->first();
                if (!$drink) continue;

                $qtyToReject = (int) $selection['quantity_to_deliver'];
                $originalQtyToReject = $qtyToReject;

                // 1. 🔥 Déduction cascade (TRANSFERRED → IN_PREPARATION)
                $this->deductFromDrinkStatus($drink, OrderMenuRestaurantItemStatus::TRANSFERRED->value, $qtyToReject);

                if ($qtyToReject > 0) {
                    $this->deductFromDrinkStatus($drink, OrderMenuRestaurantItemStatus::IN_PREPARATION->value, $qtyToReject);
                }

                // 2. 🔥 Enregistrement REJECTED
                $rejectedStatus = $drink->statuses()->firstOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid, // ⚠️ IMPORTANT
                        'status' => OrderMenuRestaurantItemStatus::REJECTED->value
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'product_uuid' => $drink->product_uuid,
                        'quantity' => 0,
                        'quantity_accumulated' => 0,
                        'created_by' => $auth->id
                    ]
                );

                $rejectedStatus->increment('quantity', $originalQtyToReject);
                $rejectedStatus->increment('quantity_accumulated', $originalQtyToReject);
                $rejectedStatus->update([
                    'updated_by' => $auth->id
                ]);

                // 3. 🔥 STATISTICS
                StatisticsOrderStatusDrink::updateOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::REJECTED->value,
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'product_uuid' => $drink->product_uuid,
                        'quantity' => $rejectedStatus->quantity,
                        'rejected_at' => now(),
                        'make_rejected_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'created_by' => $auth->id,
                    ]
                );

                $drink->update([
                    'is_rejected' => true,
                    'rejected_by' => $auth->id,
                    'rejected_at' => now(),
                    'status' => OrderMenuRestaurantItemStatus::REJECTED->value,
                    'updated_by' => $auth->id
                ]);
            }

            $this->refreshOrderStatus($order);
            $order->update(['updated_by' => $auth->id]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Rejet des boissons effectué avec succès.'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
    }
    private function deductFromDrinkStatus($drink, $statusValue, &$qtyToReject)
    {
        $statusRow = $drink->statuses()
            ->where('status', $statusValue)
            ->first();

        if ($statusRow && $statusRow->quantity > 0) {

            $deductible = min($qtyToReject, $statusRow->quantity);

            $statusRow->decrement('quantity', $deductible);
            $statusRow->decrement('quantity_accumulated', $deductible);

            $qtyToReject -= $deductible;
            if ($statusRow->fresh()->quantity <= 0) {
                $statusRow->delete();
            }
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::SetItemsInPreparation
     * @permission_desc Mettre en cours de préparation les plats selectionnées d'une commande
     */
    public function validateItemMenusInPreparation(Request $request, $uuid)
    {
        $auth = auth()->user();
        $validated = $request->validate([
            '*.item_uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
            '*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->with(['items.statuses'])->firstOrFail();
        $now = now();

        DB::beginTransaction();
        try {
            foreach ($validated as $itemData) {
                $item = $order->items->where('uuid', $itemData['item_uuid'])->first();
                if (!$item) continue;

                $qtyRequested = (int) $itemData['quantity_to_deliver'];

                // 1. VÉRIFICATION DE SÉCURITÉ
                $availableQty = $item->statuses()
                    ->whereIn('status', [
                        OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                        OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value
                    ])
                    ->sum('quantity');

                if ($qtyRequested > $availableQty) {
                    throw new \Exception(
                        "Erreur sur {$item->menu->name} : La quantité demandée ({$qtyRequested}) dépasse le nombre de portions en attente ({$availableQty})."
                    );
                }

                // 2. DÉDUCTION EN CASCADE DES STATUTS SOURCE
                $qtyRemainingToProcess = $qtyRequested;
                $sourceStatuses = [
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                    OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value
                ];

                foreach ($sourceStatuses as $statusType) {
                    if ($qtyRemainingToProcess <= 0) break;

                    $statusModel = $item->statuses()->where('status', $statusType)->first();
                    if ($statusModel && $statusModel->quantity > 0) {
                        $take = min($qtyRemainingToProcess, $statusModel->quantity);
                        $statusModel->decrement('quantity', $take);

                        $statusModel->quantity_accumulated = $statusModel->quantity;
                        $statusModel->updated_by = $auth->id;
                        $statusModel->save();


                        $qtyRemainingToProcess -= $take;
                    }
                }

                // 3. CRÉATION OU MISE À JOUR DU STATUT IN_PREPARATION
                $prepStatus = $item->statuses()->firstOrCreate(
                    ['status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'quantity' => 0,
                        'quantity_accumulated' => 0,
                        'created_by' => $auth->id
                    ]
                );

                // ⚡ recalcul exact des quantités pour éviter l'accumulation infinie
                $totalInPrep = $item->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
                    ->sum('quantity');

                $prepStatus->update([
                    'quantity' => $prepStatus->quantity + $qtyRequested,
                    'quantity_accumulated' => $prepStatus->quantity_accumulated + $qtyRequested,
                    'updated_by' => $auth->id
                ]);

                StatisticsOrderStatusMenuRestaurant::updateOrCreate(
                    [
                        'order_menu_restaurant_item_uuid' => $item->uuid,
                        'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'quantity' => $prepStatus->quantity,
                        'in_preparation_at' => $now,
                        'make_in_preparation_by' => $auth->id,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]
                );


                // 4. MISE À JOUR DE L'ITEM PARENT
                $item->update([
                    'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                    'is_rejected' => false,
                    'make_in_preparation_at' => $now,
                    'updated_by' => $auth->id
                ]);
            }

            $this->refreshOrderStatus($order);
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mise en cuisine validée avec succès.',
                'order' => $order->fresh(['items.statuses'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::transferRejectedItems
     * @permission_desc Remettre en transféré les plats rejettés d'une commande
     */
    public function transferRejectedItems(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with('items.statuses')
            ->firstOrFail();

        return DB::transaction(function () use ($validated, $order, $auth) {

            foreach ($validated['items'] as $itemData) {

                $item = $order->items->where('uuid', $itemData['item_uuid'])->first();
                if (!$item) continue;

                $qtyToTransfer = (int) $itemData['quantity_to_deliver'];

                // 🔹 récupérer REJECTED
                $rejected = $item->statuses()->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)->first();

                if (!$rejected || $rejected->quantity <= 0) continue;

                if ($qtyToTransfer > $rejected->quantity) {
                    throw new \Exception(
                        "Quantité invalide pour {$item->uuid}. Maximum autorisé : {$rejected->quantity}"
                    );
                }

                // 🔻 réduire REJECTED
                $rejected->quantity -= $qtyToTransfer;
                $rejected->quantity_accumulated = max(0, $rejected->quantity_accumulated - $qtyToTransfer);
                $rejected->updated_by = $auth->id;
                $rejected->save();

                $transferred = $item->statuses()->firstOrCreate(
                    ['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value],
                    [
                        'order_menu_restaurant_item_uuid' => $item->uuid,
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'quantity' => 0,
                        'quantity_exactly' => 0,
                        'quantity_accumulated' => 0,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]
                );

                $transferred->quantity += $qtyToTransfer;
                $transferred->quantity_exactly = $item->quantity_exactly;
                $transferred->quantity_accumulated += $qtyToTransfer;
                $transferred->updated_by = $auth->id;
                $transferred->save();

                // 🔥 update item
                $item->update([
                    'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'is_rejected' => false,
                    'updated_by' => $auth->id,
                ]);
            }

            $this->refreshOrderStatus($order->fresh());

            return response()->json([
                'status' => 'success',
                'message' => 'Les menus rejetés ont été transférés avec succès.'
            ]);
        });
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::GenerateFacture
     * @permission_desc Génerer la facture d'une commande
     */
    public function GenerateFacture(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mot de passe incorrect'
            ], 403);
        }

        return DB::transaction(function () use ($uuid, $auth) {

            $order = OrderMenuRestaurant::where('uuid', $uuid)->with(['items.virtuals'])->lockForUpdate()->firstOrFail();

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->lockForUpdate()->firstOrFail();

            foreach ($order->items as $item) {

                if ($item->is_stock_deducted) {
                    continue;
                }

                if ($item->virtuals->isEmpty()) {
                    continue;
                }

                foreach ($item->virtuals as $virtual) {

                    $qty = (int) $virtual->quantity_reserved;

                    if ($qty <= 0) {
                        continue;
                    }

                    $stock = ProductPoint::where('point_uuid', $warehouse->uuid)
                        ->where('produit_uuid', $virtual->product_uuid)
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \Exception("Stock introuvable produit {$virtual->product_uuid}");
                    }

                    if ($stock->quantity < $qty) {
                        throw new \Exception("Stock insuffisant pour produit {$virtual->product_uuid}");
                    }

                    $stock->decrement('quantity', $qty);
                    $virtual->update([
                        'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                        'updated_by' => $auth->id
                    ]);

                    MenuVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
                        ->where('product_uuid', $virtual->product_uuid)
                        ->update([
                            'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                            'updated_by' => $auth->id]);
                }

                $item->update([
                    'is_stock_deducted' => true,
                    'updated_by' => $auth->id
                ]);
            }

            $order->update([
                'updated_by' => $auth->id,
                'status' => MenuOrderStatus::FACTURATE->value,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Facture validée et stock + virtuals synchronisés'
            ]);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::transferRejectedDrinks
     * @permission_desc Remettre en transféré les boissons rejettés d'une commande
     */
    public function transferRejectedDrinks(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.drink_uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with('drinks.statuses')
            ->firstOrFail();

        return DB::transaction(function () use ($validated, $order, $auth) {

            foreach ($validated['items'] as $drinkData) {

                $drink = $order->drinks->firstWhere('uuid', $drinkData['drink_uuid']);
                if (!$drink) continue;

                $qtyToTransfer = (int) $drinkData['quantity_to_deliver'];

                // 🔹 REJECTED STATUS
                $rejected = $drink->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)
                    ->first();

                if (!$rejected || $rejected->quantity <= 0) continue;

                if ($qtyToTransfer > $rejected->quantity) {
                    throw new \Exception(
                        "Quantité invalide pour {$drink->product->name}. Max : {$rejected->quantity}"
                    );
                }

                $rejected->update([
                    'quantity' => $rejected->quantity - $qtyToTransfer,
                    'quantity_accumulated' => max(0, $rejected->quantity_accumulated - $qtyToTransfer),
                    'updated_by' => $auth->id,
                ]);

                // 🔥 2. TRANSFERRED STATUS
                $transferred = $drink->statuses()->firstOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'product_uuid' => $drink->product_uuid,
                        'quantity' => 0,
                        'quantity_accumulated' => 0,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]
                );

                $transferred->update([
                    'quantity' => $transferred->quantity + $qtyToTransfer,
                    'quantity_accumulated' => $transferred->quantity_accumulated + $qtyToTransfer,
                    'updated_by' => $auth->id,
                    'quantity_exactly' => $drink->quantity_exactly,
                ]);

                // 🔥 4. update DRINK PRINCIPAL
                $drink->update([
                    'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'is_rejected' => false,
                    'updated_by' => $auth->id,
                ]);
            }

            $this->refreshOrderStatus($order->fresh());

            return response()->json([
                'status' => 'success',
                'message' => 'Les boissons rejetées ont été transférées avec succès.'
            ]);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::SetDrinksInPreparation
     * @permission_desc Mettre en cours de préparation les boissons selectionnées d'une commande
     */
    public function SetDrinksInPreparation(Request $request, $uuid)
    {
        $auth = auth()->user();
        $validated = $request->validate([
            '*.drink_uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
            '*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->with(['drinks.statuses'])->firstOrFail();
        $now = now();

        DB::beginTransaction();
        try {
            foreach ($validated as $drinkData) {
                $drink = $order->drinks->where('uuid', $drinkData['drink_uuid'])->first();
                if (!$drink) continue;

                $qtyRequested = (int) $drinkData['quantity_to_deliver'];

                $availableQty = $drink->statuses()
                    ->whereIn('status', [
                        OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value
                    ])
                    ->sum('quantity');

                if ($qtyRequested > $availableQty) {
                    throw new \Exception(
                        "Erreur sur {$drink->product->name} : quantité demandée ({$qtyRequested}) > disponible ({$availableQty})."
                    );
                }

                // 2. DÉDUCTION EN CASCADE DES STATUTS SOURCE
                $qtyRemainingToProcess = $qtyRequested;
                $sourceStatuses = [
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value
                ];

                foreach ($sourceStatuses as $statusType) {
                    if ($qtyRemainingToProcess <= 0) break;

                    $statusModel = $drink->statuses()->where('status', $statusType)->first();
                    if ($statusModel && $statusModel->quantity > 0) {
                        $take = min($qtyRemainingToProcess, $statusModel->quantity);
                        $statusModel->decrement('quantity', $take);

                        $statusModel->quantity_accumulated = $statusModel->quantity;
                        $statusModel->updated_by = $auth->id;
                        $statusModel->save();


                        $qtyRemainingToProcess -= $take;
                    }
                }

                $prepStatus = $drink->statuses()->firstOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'product_uuid' => $drink->product_uuid,
                        'quantity' => 0,
                        'quantity_accumulated' => 0,
                        'created_by' => $auth->id
                    ]
                );


                $totalInPrep = $drink->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
                    ->sum('quantity');

                $prepStatus->update([
                    'quantity' => $prepStatus->quantity + $qtyRequested,
                    'quantity_accumulated' => $prepStatus->quantity_accumulated + $qtyRequested,
                    'updated_by' => $auth->id
                ]);

                StatisticsOrderStatusDrink::updateOrCreate(
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value
                    ],
                    [
                        'product_uuid' => $drink->product_uuid,
                        'quantity' => $prepStatus->quantity,
                        'in_preparation_at' => $now,
                        'make_in_preparation_by' => $auth->id,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]
                );


                // 4. MISE À JOUR DE L'ITEM PARENT
                $drink->update([
                    'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                    'is_rejected' => false,
                    'make_in_preparation_at' => $now,
                    'updated_by' => $auth->id
                ]);
            }

            $this->refreshOrderStatus($order);
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mise en préparation réussie.',
                'order' => $order->fresh(['drinks.statuses'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::CancelOrderMenuRestaurant
     * @permission_desc Annuler une commande
     */
    public function CancelOrderMenuRestaurant(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_cancel' => 'required|string|max:1000',
        ], [
            'reason_cancel.required' => "La raison de l'annulation est obligatoire.",
            'reason_cancel.string'   => "La raison doit être une chaîne de caractères.",
            'reason_cancel.max'      => "La raison ne doit pas dépasser 1000 caractères.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        try {
            $order->update([
                'status'          => MenuOrderStatus::CANCELLED->value,
                'reason_cancel' => $validated['reason_cancel'],
                'cancelled_at'     => now(),
                'cancelled_by'     => $auth->id,
                'updated_by'      => $auth->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Commande annulée avec succès.',
                'data'    => $order,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de l\'annulation de la commande.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::CancelOrderMenuRestaurantBySuperAdmin
     * @permission_desc Annuler une commande par le SUPER ADMIN
     */
    public function CancelOrderMenuRestaurantBySuperAdmin(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_cancel' => 'required|string|max:1000',
        ], [
            'reason_cancel.required' => "La raison de l'annulation est obligatoire.",
            'reason_cancel.string'   => "La raison doit être une chaîne de caractères.",
            'reason_cancel.max'      => "La raison ne doit pas dépasser 1000 caractères.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        try {
            $order->update([
                'status'          => MenuOrderStatus::CANCELLED->value,
                'reason_cancel' => $validated['reason_cancel'],
                'cancelled_at'     => now(),
                'cancelled_by'     => $auth->id,
                'updated_by'      => $auth->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Commande annulée avec succès.',
                'data'    => $order,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de l\'annulation de la commande.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function updateItemStatusFromPreparation(OrderMenuRestaurantItem $item, int $qtyValidated, $auth,OrderMenuRestaurant $order)
    {
        // 1. Récupération du statut source (Cuisine)
        $prepStatus = $item->statuses()->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)->first();

        if (!$prepStatus || $prepStatus->quantity <= 0) return;

        // Sécurité : on ne peut pas livrer plus que ce qui est en cuisine
        $qtyToProcess = min($qtyValidated, (int)$prepStatus->quantity);

        // Décrémenter la quantité en préparation
        $prepStatus->decrement('quantity', $qtyToProcess);

        // 2. Mise à jour ou création du statut TOTAL_DELIVERED
        $deliveryStatus = $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->first();

        if ($deliveryStatus) {
            // Mise à jour des quantités
            $deliveryStatus->update([
                'quantity'             => $deliveryStatus->quantity + $qtyToProcess,
                'quantity_accumulated' => $deliveryStatus->quantity_accumulated + $qtyToProcess,
                'updated_by'           => $auth->id
            ]);
            $finalQty = $deliveryStatus->quantity;
        } else {
            // Création du statut TOTAL_DELIVERED si inexistant
            $item->statuses()->create([
                'status'               => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                'quantity'             => $qtyToProcess,
                'quantity_accumulated' => $qtyToProcess,
                'created_by'           => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid
            ]);

            $finalQty = $qtyToProcess;
        }

        $item->update([
            'status'     => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
            'updated_by' => $auth->id
        ]);

        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity'                   => $finalQty,
                'ready_at'               => now(),
                'make_ready_by'          => $auth->id,
                'created_by'                 => $auth->id,
                'updated_by'                 => $auth->id,
            ]
        );

    }

    private function updateDrinkStatusFromPreparation(OrderRestaurantDrink $drink, int $qtyValidated, $auth, OrderMenuRestaurant $order) {
        $prepStatus = $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
            ->first();

        if (!$prepStatus || $prepStatus->quantity <= 0) {
            return;
        }

        // Sécurité
        $qtyToProcess = min($qtyValidated, (int) $prepStatus->quantity);
        $prepStatus->decrement('quantity', $qtyToProcess);

        // 2. Statut TOTAL_DELIVERED
        $deliveryStatus = $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->first();

        if ($deliveryStatus) {
            $deliveryStatus->update([
                'quantity'             => $deliveryStatus->quantity + $qtyToProcess,
                'quantity_accumulated' => $deliveryStatus->quantity_accumulated + $qtyToProcess,
                'updated_by'           => $auth->id,
            ]);

            $finalQty = $deliveryStatus->quantity;
        } else {
            $deliveryStatus = $drink->statuses()->create([
                'uuid' => (string) \Str::uuid(),
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drink->uuid,
                'product_uuid' => $drink->product_uuid,
                'status' => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                'quantity' => $qtyToProcess,
                'quantity_accumulated' => $qtyToProcess,
                'created_by' => $auth->id,
            ]);

            $finalQty = $qtyToProcess;
        }

        // 3. Update drink principal
        $drink->update([
            'status' => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
            'updated_by' => $auth->id,
        ]);

        // 4. Stats
        StatisticsOrderStatusDrink::updateOrCreate(
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drink->uuid,
                'status' => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
            ],
            [
                'product_uuid' => $drink->product_uuid,
                'quantity' => $finalQty,
                'ready_at' => now(),
                'make_ready_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateMenusForOrder
     * @permission_desc Mettre en prêt les plats selectionnées d'une commande
     */
    public function validateMenusForOrder(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            '*.item_uuid' => ['required', 'uuid'],
            '*.quantity_to_deliver' => ['required', 'integer', 'min:1'],
        ], [
            '*.item_uuid.required' => "L'identifiant de l'élément est obligatoire.",
            '*.item_uuid.uuid' => "L'identifiant de l'élément doit être un UUID valide.",
            '*.quantity_to_deliver.required' => "La quantité à livrer est obligatoire.",
            '*.quantity_to_deliver.integer' => "La quantité à livrer doit être un nombre entier.",
            '*.quantity_to_deliver.min' => "La quantité à livrer doit être au moins 1.",
        ]);

        DB::beginTransaction();

        try {
            // 🔹 Charger la commande avec les menus uniquement
            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with(['items', 'items.menu'])
                ->firstOrFail();

            $allDeliveryLogs = [];

            foreach ($request->all() as $pItem) {
                $itemUuid = $pItem['item_uuid'];
                $qtyToDeliver = (int) $pItem['quantity_to_deliver'];

                // 🔹 Chercher le menu
                $item = $order->items->firstWhere('uuid', $itemUuid);

                if (!$item) {
                    $allDeliveryLogs[] = [
                        'item_uuid' => $itemUuid,
                        'error' => 'Menu non trouvé dans la commande'
                    ];
                    continue;
                }

                $totalOrdered = (int) $item->quantity ?? 1;
                $remainingQty = $item->quantity;

                Log::info($remainingQty);

                if ($qtyToDeliver > $remainingQty) {
                    return response()->json([
                        'success' => false,
                        'message' => "Impossible de livrer {$qtyToDeliver} menus pour l'item, quantité restante : {$remainingQty}"
                    ], 422);
                }

                $this->updateItemStatusFromPreparation($item, $qtyToDeliver, $auth,$order);

                $newDeliveredTotal = $item->quantity_delivered + $qtyToDeliver;
                $newRemaining = max(0, $item->quantity - $qtyToDeliver);

                if ($newRemaining <= 0) {
                    $itemStatus = OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value;
                    $hasBeenValidated = true;
                } else {
                    $itemStatus = $item->status === OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        ? OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        : OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
                    $hasBeenValidated = false;
                }

                $item->quantity_delivered = $newDeliveredTotal;
                $item->quantity_final_used += $qtyToDeliver;
                $item->quantity = $newRemaining;
                $item->status = $itemStatus;
                $item->has_been_validated = $hasBeenValidated;
                $item->save();

                $allDeliveryLogs[] = [
                    'item_uuid' => $item->uuid,
                    'item_name' => $item->menu->name,
                    'item_type' => 'menu',
                    'quantity_ordered' => $totalOrdered,
                    'quantity_delivered_total' => $item->quantity_delivered,
                    'quantity_remaining' => $item->quantity,
                    'quantity_to_deliver' => $qtyToDeliver,
                    'status_item' => $itemStatus,
//                    'virtuals' => $virtualLogs,
                ];
            }

            // 🔹 Statut global de la commande pour les menus
            $itemsStatus = array_column($allDeliveryLogs, 'status_item');
            if (empty($itemsStatus)) {
                $orderStatus = OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;
            } else {
                $allFinished = true;
                foreach ($itemsStatus as $status) {
                    if ($status !== OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value) {
                        $allFinished = false;
                        break;
                    }
                }
                $orderStatus = $allFinished
                    ? OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
            }

            $this->refreshOrderStatus($order);

            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'order_status' => $orderStatus,
                'delivery_log' => $allDeliveryLogs,
                'message' => 'Validation des menus effectuée avec succès!.'
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    protected function deliverMenuVirtualsProportional(string $orderUuid, string $itemUuid, int $qtyToDeliver, int $totalOrdered): array
    {
        $virtuals = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
            ->where('item_uuid', $itemUuid)
            ->where('item_type', 'menu')
            ->get();

        $deliveryLogs = [];

        $proportion = min(1, $qtyToDeliver / max(1, $totalOrdered));

        foreach ($virtuals as $virtual) {
            $reserved = (int) $virtual->quantity_reserved;

            $simDelivered = (int) round($reserved * $proportion);

            $status = match(true) {
                $simDelivered >= $reserved => VirtualOrderMenuRestaurantStatus::DELIVERED,
                $simDelivered > 0 => VirtualOrderMenuRestaurantStatus::PARTIALLY_DELIVERED,
                default => VirtualOrderMenuRestaurantStatus::RESERVED,
            };

            $virtual->quantity_reserved = max(0, $reserved - $simDelivered);
            $virtual->quantity_delivered = $simDelivered;
            $virtual->status = $status->value;
            $virtual->save();

            $deliveryLogs[] = [
                'virtual_uuid' => $virtual->uuid,
                'product_uuid' => $virtual->product_uuid,
                'quantity_reserved' => $reserved,
                'quantity_delivered' => $simDelivered,
                'quantity_to_serve' => max(0, $reserved - $simDelivered),
                'status' => $status->value,
            ];
        }

        return $deliveryLogs;
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateDrinksForOrder
     * @permission_desc Mettre en prêt les boissons selectionnés d'une commande
     */
    public function validateDrinksForOrder(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $request->validate([
            '*.item_uuid' => ['required', 'uuid'],
            '*.quantity_to_deliver' => ['required', 'integer', 'min:1'],
        ], [
            '*.item_uuid.required' => "L'identifiant de l'élément est obligatoire.",
            '*.item_uuid.uuid' => "L'identifiant de l'élément doit être un UUID valide.",
            '*.quantity_to_deliver.required' => "La quantité à livrer est obligatoire.",
            '*.quantity_to_deliver.integer' => "La quantité à livrer doit être un nombre entier.",
            '*.quantity_to_deliver.min' => "La quantité à livrer doit être au moins 1.",
        ]);

        DB::beginTransaction();

        try {
            // 🔹 Charger la commande avec les drinks uniquement
            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with(['drinks', 'drinks.product'])
                ->firstOrFail();

            $allDeliveryLogs = [];

            foreach ($request->all() as $pItem) {
                $itemUuid = $pItem['item_uuid'];
                $qtyToDeliver = (int) $pItem['quantity_to_deliver'];

                // 🔹 Chercher la boisson
                $item = $order->drinks->firstWhere('uuid', $itemUuid);

                if (!$item) {
                    $allDeliveryLogs[] = [
                        'item_uuid' => $itemUuid,
                        'error' => 'Boisson non trouvée dans la commande'
                    ];
                    continue;
                }

                $totalOrdered = (int) $item->quantity ?? 1;
                $remainingQty = max(0, $item->quantity_exactly - $item->quantity_delivered);

                if ($qtyToDeliver > $remainingQty) {
                    return response()->json([
                        'success' => false,
                        'message' => "Impossible de livrer {$qtyToDeliver} boissons pour l'item, quantité restante : {$remainingQty}"
                    ], 422);
                }

                $this->updateDrinkStatusFromPreparation($item, $qtyToDeliver, $auth, $order);

                $newDeliveredTotal = $item->quantity_delivered + $qtyToDeliver;
                $newRemaining = max(0, $totalOrdered - $newDeliveredTotal);


                if ($newRemaining <= 0) {
                    $itemStatus = OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value;
                    $hasBeenValidated = true;
                } else {
                    $itemStatus = $item->status === OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        ? OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        : OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;

                    $hasBeenValidated = false;
                }

                $item->quantity_delivered = $newDeliveredTotal;
                $item->quantity_final_used += $qtyToDeliver;
                $item->quantity = $newRemaining;
                $item->status = $itemStatus;
                $item->has_been_validated = $hasBeenValidated;

                $item->save();

                $allDeliveryLogs[] = [
                    'item_uuid' => $item->uuid,
                    'item_name' => $item->product->name,
                    'item_type' => 'drink',
                    'quantity_ordered' => $totalOrdered,
                    'quantity_delivered_total' => $item->quantity_delivered,
                    'quantity_remaining' => $item->quantity,
                    'quantity_to_deliver' => $qtyToDeliver,
                    'status_item' => $itemStatus,
                ];
            }

            // 🔹 Statut global de la commande pour les drinks
            $itemsStatus = array_column($allDeliveryLogs, 'status_item');
            $orderStatus = !empty($itemsStatus)
                ? (count(array_unique($itemsStatus)) === 1 ? $itemsStatus[0] : OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value)
                : OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;

            $this->refreshOrderStatus($order);

            $order->update([
                'updated_by' => $auth->id,
            ]);
            DB::commit();

            return response()->json([
                'success' => true,
                'order_status' => $orderStatus,
                'delivery_log' => $allDeliveryLogs,
                'message' => 'Validation des boissons effectuée avec succès!.'
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    private function updateItemStatusToFinalDelivery(OrderMenuRestaurantItem $item, int $qtyValidated, $auth,OrderMenuRestaurant $order)
    {
        $sourceStatus = $item->statuses()->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)->first();

        if (!$sourceStatus || $sourceStatus->quantity <= 0) return;
        $qtyToProcess = min($qtyValidated, $sourceStatus->quantity);
        $sourceStatus->decrement('quantity', $qtyToProcess);
        $deliveredStatus = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::DELIVERED->value],
            [
                'quantity'             => 0,
                'quantity_accumulated' => 0,
                'created_by'           => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid // ✅ ajouté
            ]
        );
        $deliveredStatus->increment('quantity', $qtyToProcess);
        $deliveredStatus->increment('quantity_accumulated', $qtyToProcess);
        $deliveredStatus->update(['updated_by' => $auth->id, 'order_menu_restaurant_uuid' => $order->uuid]);
        $item->update([
            'status'     => OrderMenuRestaurantItemStatus::DELIVERED->value,
            'updated_by' => $auth->id
        ]);
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => OrderMenuRestaurantItemStatus::DELIVERED->value
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $deliveredStatus->quantity,
                'delivered_at' => now(),
                'make_delivered_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }

    private function updateDrinkStatusToFinalDelivery(OrderRestaurantDrink $drink, int $qtyValidated, $auth, OrderMenuRestaurant $order) {
        $sourceStatus = $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->first();

        if (!$sourceStatus || $sourceStatus->quantity <= 0) return;
        $qtyToProcess = min($qtyValidated, $sourceStatus->quantity);
        $sourceStatus->decrement('quantity', $qtyToProcess);

        $deliveredStatus = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                'order_restaurant_drink_uuid' => $drink->uuid,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        // 3. Mise à jour SAFE
        $deliveredStatus->increment('quantity', $qtyToProcess);
        $deliveredStatus->increment('quantity_accumulated', $qtyToProcess);

        $deliveredStatus->update([
            'updated_by' => $auth->id,
        ]);

        // 4. Update drink principal
        $drink->update([
            'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
            'updated_by' => $auth->id,
        ]);

        // 5. STATISTICS
        StatisticsOrderStatusDrink::updateOrCreate(
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drink->uuid,
                'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
            ],
            [
                'product_uuid' => $drink->product_uuid,
                'quantity' => $deliveredStatus->quantity,
                'delivered_at' => now(),
                'make_delivered_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }


    private function rejectFromTotalDelivered(OrderMenuRestaurantItem $item, int $qtyToReject, $auth,OrderMenuRestaurant $order)
    {
        // 1. Récupération du statut source (TOTAL_DELIVERED)
        $sourceStatus = $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->first();

        if (!$sourceStatus || $sourceStatus->quantity <= 0) return;

        // On ne peut pas rejeter plus que ce qui est disponible
        $qtyToProcess = min($qtyToReject, $sourceStatus->quantity);

        // Décrémentation du statut source
        $sourceStatus->decrement('quantity', $qtyToProcess);

        // 2. Mise à jour ou création du statut REJECTED_FOR_NEW_UPDATE
        $rejectedStatus = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value],
            [
                'quantity'             => 0,
                'quantity_accumulated' => 0,
                'created_by'           => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid
            ]
        );

        // Incrémentation des compteurs
        $rejectedStatus->increment('quantity', $qtyToProcess);
        $rejectedStatus->increment('quantity_accumulated', $qtyToProcess);
        $rejectedStatus->update(['updated_by' => $auth->id, 'order_menu_restaurant_uuid' => $order->uuid]);

        // 3. Mise à jour de l'item parent
        $item->update([
            'status'       => OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
            'is_rejected'  => true,
            'rejected_by'  => $auth->id,
            'rejected_at'  => now(),
            'updated_by'   => $auth->id
        ]);
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $rejectedStatus->quantity,
                'cancel_for_new_update_at' => now(),
                'make_cancel_for_new_update_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }

    private function rejectDrinkFromTotalDelivered(OrderRestaurantDrink $drink, int $qtyToReject, $auth, OrderMenuRestaurant $order)
    {
        $source = $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->first();

        if (!$source || $source->quantity <= 0) return;

        $qty = min($qtyToReject, $source->quantity);

        $source->decrement('quantity', $qty);

        $rejected = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drink->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'created_by' => $auth->id,
            ]
        );

        $rejected->increment('quantity', $qty);
        $rejected->increment('quantity_accumulated', $qty);

        $rejected->update([
            'updated_by' => $auth->id,
        ]);
    }

    private function rejectFromDelivered(OrderMenuRestaurantItem $item, int $qtyToReject, $auth, OrderMenuRestaurant $order) {
        $sourceStatus = $item->statuses()->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)->first();
        if (!$sourceStatus || $sourceStatus->quantity <= 0) return;

        $qtyToProcess = min($qtyToReject, $sourceStatus->quantity);

        $sourceStatus->decrement('quantity', $qtyToProcess);

        if ($sourceStatus->fresh()->quantity <= 0) {
            $sourceStatus->update(['quantity_accumulated' => 0]);
        }

        $rejectedStatus = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value],
            [
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'created_by' => $auth->id,
                'order_menu_restaurant_uuid' => $order->uuid
            ]
        );

        $rejectedStatus->update([
            'quantity' => $rejectedStatus->quantity + $qtyToProcess,
            'quantity_accumulated' => $rejectedStatus->quantity_accumulated + $qtyToProcess,
            'updated_by' => $auth->id,
        ]);

        $item->update([
            'status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
            'is_rejected' => true,
            'rejected_by' => $auth->id,
            'rejected_at' => now(),
            'updated_by' => $auth->id
        ]);
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $rejectedStatus->quantity,
                'cancel_for_new_update_at' => now(),
                'make_cancel_for_new_update_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }

    private function rejectDrinkFromDelivered(OrderRestaurantDrink $drink, int $qtyToReject, $auth, OrderMenuRestaurant $order) {
        $sourceStatus = $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->first();

        if (!$sourceStatus || $sourceStatus->quantity <= 0) return;

        // 🔹 2. Quantité à traiter
        $qtyToProcess = min($qtyToReject, $sourceStatus->quantity);

        // 🔻 Décrémenter DELIVERED
        $sourceStatus->decrement('quantity', $qtyToProcess);

        if ($sourceStatus->fresh()->quantity <= 0) {
            $sourceStatus->update([
                'quantity_accumulated' => 0,
                'updated_by' => $auth->id
            ]);
        }

        // 🔹 3. Créer / update REJECTED_AFTER_VALIDATION
        $rejectedStatus = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
                'order_restaurant_drink_uuid' => $drink->uuid // ⚠️ IMPORTANT
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $drink->product_uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        $rejectedStatus->update([
            'quantity' => $rejectedStatus->quantity + $qtyToProcess,
            'quantity_accumulated' => $rejectedStatus->quantity_accumulated + $qtyToProcess,
            'updated_by' => $auth->id,
        ]);

        // 🔹 4. Update DRINK principal
        $drink->update([
            'status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
            'is_rejected' => true,
            'rejected_by' => $auth->id,
            'rejected_at' => now(),
            'updated_by' => $auth->id
        ]);

        // 🔹 5. STATISTICS
        StatisticsOrderStatusDrink::updateOrCreate(
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drink->uuid,
                'status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value
            ],
            [
                'product_uuid' => $drink->product_uuid,
                'quantity' => $rejectedStatus->quantity,
                'cancel_for_new_update_at' => now(),
                'make_cancel_for_new_update_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );
    }

    private function refreshItemStatus(OrderMenuRestaurantItem $item, $auth)
    {
        $item->refresh();
        $deliveredQty = (int) $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');
        $requiredQty = (int) $item->quantity_exactly;
        if ($deliveredQty === $requiredQty && $requiredQty > 0) {
            $item->status = OrderMenuRestaurantItemStatus::DELIVERED->value;
        } elseif ($deliveredQty > 0) {
            $item->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
        }

        $item->updated_by = $auth->id;
        $item->save();
    }

    private function refreshDrinkStatus(OrderRestaurantDrink $drink, $auth)
    {
        $drink->refresh();

        $deliveredQty = (int) $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');

        $requiredQty = (int) $drink->quantity_exactly;

        if ($deliveredQty === $requiredQty && $requiredQty > 0) {
            $drink->status = OrderMenuRestaurantItemStatus::DELIVERED->value;
        } elseif ($deliveredQty > 0) {
            $drink->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
        }

        $drink->updated_by = $auth->id;
        $drink->save();
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateAndDeductStockMenus
     * @permission_desc Mettre en servie les plats selectionnés d'une commande
     */
    public function validateAndDeductStockMenus(Request $request ,string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string',
            'items' => 'required|array',
            'items.*.item_uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['items.virtuals', 'items.statuses', 'items.menu'])
                ->firstOrFail();

            $stockLogs = [];

            foreach ($request->items as $pItem) {
                $item = $order->items->firstWhere('uuid', $pItem['item_uuid']);
                if (!$item) continue;

                $qtyToDeliver = (int) $pItem['quantity_to_deliver'];

                // 🔹 Mettre à jour les statuses dans la table status
                $this->updateItemStatusToFinalDelivery($item, $qtyToDeliver, $auth,$order);

                $this->refreshItemStatus($item, $auth);
            }
            $this->refreshOrderStatus($order);
            $order->update(['updated_by' => $auth->id]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validation effectuée avec succès !',
                'order_status' => $order->status,
                'logs' => $stockLogs
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateAndDeductStockDrinks
     * @permission_desc Mettre en servie les boissons d'une commande marqué prête
     */
    public function validateAndDeductStockDrinks(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.item_uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)->with(['drinks'])->firstOrFail();

            $stockLogs = [];
            $items = collect($request->input('items'));

            foreach ($items as $itemData) {

                $drink = $order->drinks->firstWhere('uuid', $itemData['item_uuid']);

                if (!$drink) continue;

                $toDeduct = (int) $itemData['quantity_to_deliver'];

                if ($toDeduct <= 0) continue;

                $this->updateDrinkStatusToFinalDelivery($drink, $toDeduct, $auth, $order);

                $this->refreshDrinkStatus($drink, $auth);
            }

            $this->refreshOrderStatus($order);

            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validation effectuée avec succès.',
                'order_status' => $order->status,
                'logs' => $stockLogs
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation des boissons : ' . $e->getMessage()
            ], 422);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::cancelMenuValidation
     * @permission_desc Rejetter les plats d'une commande marqué prêt(s)
     */
    public function cancelMenuValidation(Request $request, string $orderUuid)
    {
        $auth = auth()->user();
        $request->validate([
            'items' => 'required|array',
            'items.*.item_uuid' => 'required|exists:orders_menu_restaurant_items,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['items.virtuals', 'items.statuses'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $restorationLogs = [];
            $selectedItems = collect($request->items)->keyBy('item_uuid');

            foreach ($order->items as $item) {
                if (!isset($selectedItems[$item->uuid])) continue;

                $data = $selectedItems[$item->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

                // 1. GESTION DES STATUTS (Historique inclus)
                $this->rejectFromTotalDelivered($item, $qtyToCancel, $auth,$order);

                // 2. RESTAURATION DE L'ITEM PARENT
                $actuallyDelivered = (int) $item->quantity_delivered;
                $restoreAmount = min($qtyToCancel, $actuallyDelivered);

                if ($restoreAmount > 0) {
                    $item->quantity_delivered -= $restoreAmount; // 2 - 1 = 1 ✅
                    $item->quantity_final_used -= $restoreAmount; // idem
                    $item->quantity += $restoreAmount; // 1 + 1 = 2 ✅
                    Log::info("Annulation du menu {$item->menu->name} ({$item->uuid}): qty annulée = {$restoreAmount}, quantity_delivered = {$item->quantity_delivered}, quantity_final_used = {$item->quantity_final_used}, quantity restante = {$item->quantity}");
                }

                $item->status = OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;
                $item->reason = $reason;
                $item->is_rejected = true;
                $item->updated_by = $auth->id;
                $item->save();
            }

            $order->update([
                'status' => MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                'updated_by' => $auth->id,
            ]);

            $this->refreshOrderStatus($order);
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Rejet du prêt réussie avec succès', 'logs' => $restorationLogs]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::cancelMenuValidationAfterValidation
     * @permission_desc Rejetter les plats d'une commande déjà servi(s)
     */
    public function cancelMenuValidationAfterValidation(Request $request, string $orderUuid)
    {
        $auth = auth()->user();
        $request->validate([
            'items' => 'required|array',
            'items.*.item_uuid' => 'required|exists:orders_menu_restaurant_items,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['items.virtuals', 'items.statuses'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $restorationLogs = [];
            $selectedItems = collect($request->items)->keyBy('item_uuid');

            foreach ($order->items as $item) {
                if (!isset($selectedItems[$item->uuid])) continue;

                $data = $selectedItems[$item->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

                // 1. GESTION DES STATUTS (Historique inclus)
                $this->rejectFromDelivered($item, $qtyToCancel, $auth,$order);

                $actuallyDelivered = (int) $item->quantity_final_used;
                $restoreAmount = min($qtyToCancel, $actuallyDelivered);

                if ($restoreAmount > 0) {
                    $item->quantity_delivered = 0;
                    $item->quantity_final_used -= $restoreAmount; // idem
                    $item->quantity = $item->quantity + $restoreAmount;
                    Log::info("Annulation du menu {$item->menu->name} ({$item->uuid}): qty annulée = {$restoreAmount}, quantity_delivered = {$item->quantity_delivered}, quantity_final_used = {$item->quantity_final_used}, quantity restante = {$item->quantity}");
                }

                $item->status = OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value;
                $item->reason = $reason;
                $item->is_rejected = true;
                $item->updated_by = $auth->id;
                $item->save();
            }

            $order->update([
                'status' => MenuOrderStatus::REJECTED_AFTER_VALIDATION->value,
                'updated_by' => $auth->id,
            ]);

            $this->refreshOrderStatus($order);
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Rejet du prêt réussie avec succès', 'logs' => $restorationLogs]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::cancelDrinkValidationAfterValidation
     * @permission_desc Rejetter les boissons d'une commande déjà servie(s)
     */
    public function cancelDrinkValidationAfterValidation(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'items' => 'required|array',
            'items.*.item_uuid' => 'required|exists:order_restaurannts_drinks,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['drinks.virtuals', 'drinks.statuses', 'drinks.product'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();

            $restorationLogs = [];
            $selectedItems = collect($request->items)->keyBy('item_uuid');

            foreach ($order->drinks as $drink) {

                if (!isset($selectedItems[$drink->uuid])) continue;

                $data = $selectedItems[$drink->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

                // 🔥 1. Gestion des statuts (IMPORTANT)
                $this->rejectDrinkFromDelivered($drink, $qtyToCancel, $auth, $order);

                // 🔹 2. Ajustement des quantités
                $actuallyDelivered = (int) $drink->quantity_final_used;
                $restoreAmount = min($qtyToCancel, $actuallyDelivered);

                if ($restoreAmount > 0) {
                    $drink->quantity_delivered = 0;
                    $drink->quantity_final_used -= $restoreAmount;
                    $drink->quantity += $restoreAmount;

                    Log::info("Annulation boisson {$drink->product->name} ({$drink->uuid}) : qty annulée = {$restoreAmount}");
                }

                // 🔹 3. Update drink principal
                $drink->status = OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value;
                $drink->reason = $reason;
                $drink->is_rejected = true;
                $drink->updated_by = $auth->id;
                $drink->save();
            }

            // 🔥 update commande
            $order->update([
                'status' => MenuOrderStatus::REJECTED_AFTER_VALIDATION->value,
                'updated_by' => $auth->id,
            ]);

            $this->refreshOrderStatus($order);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation des boissons après validation effectuée avec succès',
                'logs' => $restorationLogs
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }




    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::cancelDrinkValidation
     * @permission_desc Rejetter les boissons d'une commande marqué comme prêt
     */
    public function cancelDrinkValidation(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'items' => 'required|array',
            'items.*.item_uuid' => 'required|exists:order_restaurannts_drinks,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['drinks.virtuals', 'drinks.statuses', 'drinks.product'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();

            $logs = [];
            $selected = collect($request->items)->keyBy('item_uuid');

            foreach ($order->drinks as $drink) {

                if (!isset($selected[$drink->uuid])) continue;

                $data = $selected[$drink->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

                // 🔥 1. gérer statuts (TOTAL_DELIVERED → REJECTED_AFTER_VALIDATION)
                $this->rejectDrinkFromTotalDelivered($drink, $qtyToCancel, $auth, $order);

                // 🔥 2. restaurer quantités drink
                $actuallyDelivered = (int) $drink->quantity_final_used;
                $restoreAmount = min($qtyToCancel, $actuallyDelivered);

                if ($restoreAmount > 0) {
                    $drink->quantity_delivered = 0;
                    $drink->quantity_final_used -= $restoreAmount;
                    $drink->quantity += $restoreAmount;
                }

                $drink->status = OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;
                $drink->reason = $reason;
                $drink->is_rejected = true;
                $drink->updated_by = $auth->id;
                $drink->save();

                // 🔥 3. virtuals drinks (si utilisés)
                foreach ($drink->virtuals->where('item_type', 'drink') as $v) {

                    $vQtyDelivered = (int) $v->quantity_delivered;
                    $qtyToRestoreVirtual = min($qtyToCancel, $vQtyDelivered);

                    if ($qtyToRestoreVirtual <= 0) continue;

                    // restaurer stock si déjà consommé
                    if ($v->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {
                        ProductPoint::where('produit_uuid', $v->product_uuid)
                            ->where('point_uuid', $warehouse->uuid)
                            ->increment('quantity', $qtyToRestoreVirtual);
                    }

                    $v->quantity_reserved += $qtyToRestoreVirtual;
                    $v->quantity_delivered -= $qtyToRestoreVirtual;
                    $v->status = OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;
                    $v->save();

                    $logs[] = [
                        'drink_uuid' => $drink->uuid,
                        'product' => $v->product_uuid,
                        'restored_qty' => $qtyToRestoreVirtual,
                        'reason' => $reason
                    ];
                }
            }

            $order->update([
                'status' => MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                'updated_by' => $auth->id,
            ]);

            $this->refreshOrderStatus($order);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation après validation des boissons réussie.',
                'logs' => $logs
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::DeleteOrderMenuRestaurantNotDelivered
     * @permission_desc Supprimer les plats non servies d'une commande
     */
    public function DeleteOrderMenuRestaurantNotDelivered(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => ['required', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['required', 'uuid'],
        ], [
            'password.required' => "Le mot de passe est obligatoire.",
            'password.string' => "Le mot de passe doit être une chaîne de caractères.",
            'items.required' => "La liste des éléments est obligatoire.",
            'items.array' => "Les éléments doivent être envoyés sous forme de tableau.",
            'items.*.required' => "Chaque élément sélectionné est obligatoire.",
            'items.*.uuid' => "Chaque élément sélectionné doit être un UUID valide.",
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();

            // 🔹 Vérifier si un menu sélectionné est déjà servi
            $hasDeliveredMenu = $order->items()
                ->whereIn('uuid', $request->items)
                ->whereIn('status', [
                    OrderMenuRestaurantItemStatus::DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                ])
                ->exists();

            if ($hasDeliveredMenu) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer un menu déjà servi ou partiellement servi.'
                ], 422);
            }

            // 🔹 Menus supprimables
            $items = $order->items()
                ->whereIn('uuid', $request->items)
                ->whereIn('status', [
                    OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PENDING->value,
                    OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                    OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
                    OrderMenuRestaurantItemStatus::REJECTED->value
                ])
                ->where('quantity_delivered', 0)
                ->with('virtuals')
                ->get();

            $deletedMenus = $items->map(function ($item) {
                return [
                    'item_uuid' => $item->uuid,
                    'menu_uuid' => $item->menu_uuid,
                ];
            });

            // 🔹 Supprimer les virtuals liés
            foreach ($items as $item) {
                $item->virtuals()->delete();
            }

            // 🔹 Supprimer les menus
            $order->items()->whereIn('uuid', $items->pluck('uuid'))->delete();

            $this->refreshOrderStatus($order->fresh());

            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Menus sélectionnés supprimés avec succès.',
                'deleted_menus' => $deletedMenus
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 422);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::DeleteOrderDrinksNotDelivered
     * @permission_desc Supprimer les boissons non servies d'une commande
     */
    public function DeleteOrderDrinksNotDelivered(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => ['required', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['required', 'uuid'],
        ], [
            'password.required' => "Le mot de passe est obligatoire.",
            'password.string' => "Le mot de passe doit être une chaîne de caractères.",
            'items.required' => "La liste des éléments est obligatoire.",
            'items.array' => "Les éléments doivent être envoyés sous forme de tableau.",
            'items.*.required' => "Chaque élément sélectionné est obligatoire.",
            'items.*.uuid' => "Chaque élément sélectionné doit être un UUID valide.",
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();

            // 🔹 Vérifier si une boisson sélectionnée est déjà livrée
            $hasDeliveredDrink = $order->drinks()
                ->whereIn('uuid', $request->items)
                ->whereIn('status', [
                    OrderMenuRestaurantItemStatus::DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                ])
                ->exists();

            if ($hasDeliveredDrink) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une boisson déjà servie ou partiellement servie.'
                ], 422);
            }

            // 🔹 Récupérer les boissons supprimables
            $drinks = $order->drinks()
                ->whereIn('uuid', $request->items)
                ->whereIn('status', [
                    OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PENDING->value,
                    OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                    OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
                    OrderMenuRestaurantItemStatus::REJECTED->value
                ])
                ->where('quantity_delivered', 0)
                ->get();

            $deletedDrinks = $drinks->map(function ($drink) {
                return [
                    'drink_uuid' => $drink->uuid,
                    'product_uuid' => $drink->product_uuid,
                ];
            });

            // 🔹 Suppression directe
            $order->drinks()
                ->whereIn('uuid', $drinks->pluck('uuid'))
                ->delete();

            $this->refreshOrderStatus($order->fresh());

            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Boissons sélectionnées supprimées avec succès.',
                'deleted_drinks' => $deletedDrinks
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage(),
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::updateMenuItemQuantity
     * @permission_desc Réduire les quantitées des plats non servis d'une commande
     */
    public function updateMenuItemQuantity(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'items' => ['required', 'array'],
            'items.*.uuid' => ['required', 'exists:orders_menu_restaurant_items,uuid'],
            'items.*.new_quantity' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => "La liste des éléments est obligatoire.",
            'items.array' => "Les éléments doivent être envoyés sous forme de tableau.",
            'items.*.uuid.required' => "Chaque élément doit être sélectionné.",
            'items.*.uuid.exists' => "L'élément sélectionné n'existe pas.",
            'items.*.new_quantity.required' => "La quantité est obligatoire pour chaque élément.",
            'items.*.new_quantity.integer' => "La quantité doit être un nombre entier.",
            'items.*.new_quantity.min' => "La quantité doit être au moins de 1.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $orderUuid)
            ->with('items')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $updatedItems = [];

            foreach ($request->items as $itemData) {

                $item = $order->items->where('uuid', $itemData['uuid'])->first();
                if (!$item) continue;

                // 🔹 Statuts autorisés pour modification
                $allowedStatuses = [
                    OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
                    OrderMenuRestaurantItemStatus::REJECTED->value,
                ];

                if (!in_array($item->status, $allowedStatuses, true)) {
                    throw new \Exception(
                        "Impossible de modifier la quantité du plat {$item->menu->name} car son statut actuel est "
                        . OrderMenuRestaurantItemStatus::safeLabel($item->status)
                        . "."
                    );
                }

                $deliveredQty = (int) $item->quantity_final_used;
                $currentQty   = (int) $item->quantity_exactly;
                $reduceQty    = (int) $itemData['new_quantity'];

                $maxReducible = $currentQty - $deliveredQty;

                // ❌ impossible de retirer plus que ce qui reste à servir
                if ($reduceQty > $maxReducible) {
                    throw new \Exception(
                        "Impossible : vous ne pouvez retirer que {$maxReducible} quantité(s) pour le plat {$item->menu->name}."
                    );
                }

                if ($deliveredQty > 0){
                    throw new \Exception(
                        "Impossible : vous ne pouvez pas supprimé le menu {$item->menu->name} parce que nous avons {$deliveredQty} en attente ."
                    );
                }

                // 🔹 Mise à jour des quantités
                $newQtyTotal = $currentQty - $reduceQty;
                $item->quantity_exactly = $newQtyTotal;
                $item->quantity = $newQtyTotal;
                $item->total_price = $newQtyTotal * $item->unit_price;



                $statusesToTransfer = [
                    OrderMenuRestaurantItemStatus::REJECTED->value,
                    OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                ];

                if (in_array($item->status, $statusesToTransfer)) {
                    $item->status = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
                }
                else {
                    if ($deliveredQty === 0) {
                        $item->status = OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                    } elseif ($newQtyTotal === $deliveredQty) {
                        $item->status = OrderMenuRestaurantItemStatus::DELIVERED->value;
                    } elseif ($newQtyTotal > $deliveredQty) {
                        $item->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                    } else {
                        $item->status = OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value;
                    }
                }
                $item->save();
                $updatedItems[] = $item;
            }

            // 🔹 Rafraîchir le statut global de la commande
            $this->refreshOrderStatus($order->fresh());

            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quantités mises à jour avec succès.',
                'items' => $updatedItems
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::updateDrinksQuantity
     * @permission_desc Réduire les quantitées des boissons non servis d'une commande
     */
    public function updateDrinksQuantity(Request $request, string $orderUuid)
    {
        $auth = auth()->user();

        $request->validate([
            'items' => ['required', 'array'],
            'items.*.uuid' => ['required', 'exists:order_restaurannts_drinks,uuid'],
            'items.*.new_quantity' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => "La liste des éléments est obligatoire.",
            'items.array' => "Les éléments doivent être envoyés sous forme de tableau.",
            'items.*.uuid.required' => "Chaque élément doit être sélectionné.",
            'items.*.uuid.exists' => "L'élément sélectionné n'existe pas.",
            'items.*.new_quantity.required' => "La quantité est obligatoire pour chaque boisson.",
            'items.*.new_quantity.integer' => "La quantité doit être un nombre entier.",
            'items.*.new_quantity.min' => "La quantité doit être au moins de 1.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $orderUuid)
            ->with('drinks')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $updatedDrinks = [];

            foreach ($request->items as $itemData) {

                $drink = $order->drinks->where('uuid', $itemData['uuid'])->first();
                if (!$drink) continue;

                // 🔹 Statuts autorisés pour modification
                $allowedStatuses = [
                    OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    OrderMenuRestaurantItemStatus::NEW_REJECTED->value,
                    OrderMenuRestaurantItemStatus::REJECTED->value,
                ];

                if (!in_array($drink->status, $allowedStatuses, true)) {
                    throw new \Exception(
                        "Impossible de réduire la quantité de la boisson {$drink->product->name} car son statut actuel est "
                        . OrderMenuRestaurantItemStatus::safeLabel($drink->status)
                        . "."
                    );
                }

                $deliveredQty = (int) $drink->quantity_final_used;
                $currentQty   = (int) $drink->quantity_exactly;
                $reduceQty    = (int) $itemData['new_quantity'];

                $maxReducible = $currentQty - $deliveredQty;

                if ($reduceQty > $maxReducible) {
                    throw new \Exception(
                        "Impossible : vous ne pouvez retirer que {$maxReducible} quantités pour la boisson {$drink->product->name}."
                    );
                }

                if ($deliveredQty > 0){
                    throw new \Exception(
                        "Impossible : vous ne pouvez pas retirer la quantitée {$reduceQty} de la boisson {$drink->product->name} parce que nous avons {$deliveredQty} en attente ."
                    );
                }

                // 🔹 Calcul du nouveau total
                $newTotalQty = $currentQty - $reduceQty;
                $drink->quantity_exactly = $newTotalQty;
                $drink->quantity = $newTotalQty;
                $drink->total_price = $newTotalQty * $drink->unit_price;


                $statusesToTransfer = [
                    OrderMenuRestaurantItemStatus::REJECTED->value,
                    OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                ];

                if (in_array($drink->status, $statusesToTransfer)) {
                    $drink->status = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
                }
                else {
                if ($deliveredQty === 0) {
                    // Rien n'a encore été servi
                    $drink->status = OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                } elseif ($newTotalQty === $deliveredQty) {
                    // Tout ce qui reste correspond déjà à ce qui a été servi
                    $drink->status = OrderMenuRestaurantItemStatus::DELIVERED->value;
                } elseif ($newTotalQty > $deliveredQty) {
                    // Une partie a été servie, une autre reste
                    $drink->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                } else {
                    // Sécurité
                    $drink->status = OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value;
                }
                }

                $drink->save();
                $updatedDrinks[] = $drink;
            }

            // 🔹 Rafraîchir statut global de la commande
            $this->refreshOrderStatus($order->fresh());
            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quantités des boissons mises à jour avec succès.',
                'items' => $updatedDrinks
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::increaseMenuItemQuantity
     * @permission_desc Augmenter les quantitées des menus d'une commande
     */
    public function increaseMenuItemQuantity(Request $request, string $orderUuid)
    {
        $auth = auth()->user();
        $request->validate([
            'items' => ['required', 'array'],
            'items.*.uuid' => ['required', 'exists:orders_menu_restaurant_items,uuid'],
            'items.*.quantity_to_add' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => "La liste des éléments est obligatoire.",
            'items.array' => "Les éléments doivent être envoyés sous forme de tableau.",
            'items.*.uuid.required' => "Chaque élément doit être sélectionné.",
            'items.*.uuid.exists' => "L'élément sélectionné n'existe pas.",
            'items.*.quantity_to_add.required' => "La quantité à ajouter est obligatoire pour chaque élément.",
            'items.*.quantity_to_add.integer' => "La quantité doit être un nombre entier.",
            'items.*.quantity_to_add.min' => "La quantité doit être au moins de 1.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $orderUuid)
            ->with('items')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $updatedItems = [];

            foreach ($request->items as $itemData) {

                $item = $order->items->where('uuid', $itemData['uuid'])->first();

                if (!$item) continue;

                $allowedStatuses = [
                    OrderMenuRestaurantItemStatus::DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
                ];

                if (!in_array($item->status, $allowedStatuses, true)) {
                    throw new \Exception(
                        "Impossible d'ajouter de la quantité pour le plat {$item->menu->name} car son statut actuel est {$item->status}."
                    );
                }

                $addQty = (int) $itemData['quantity_to_add'];

                // 🔹 Augmentation
                $item->quantity_exactly += $addQty;
                $item->quantity += $addQty;
                $item->total_price = $item->quantity_exactly * $item->unit_price;

                // 🔹 Statut transféré pour les items modifiés
                if ($item->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {
                    $item->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                } elseif ($item->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value) {
                    $item->status = OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                } else {
                    $item->status = OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                }

                $item->save();
                $updatedItems[] = $item;
            }

            // 🔹 Rafraîchir statut de la commande
            $this->refreshOrderStatus($order->fresh());
            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quantités ajoutées avec succès.',
                'items' => $updatedItems
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::increaseDrinksQuantity
     * @permission_desc Augmenter les quantitées des boissons d'une commande
     */
    public function increaseDrinksQuantity(Request $request, string $orderUuid)
    {
        $auth = auth()->user();
        $request->validate([
            'items' => ['required', 'array'],
            'items.*.uuid' => ['required', 'exists:order_restaurannts_drinks,uuid'],
            'items.*.quantity_to_add' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => "La liste des éléments est obligatoire.",
            'items.array' => "Les éléments doivent être envoyés sous forme de tableau.",
            'items.*.uuid.required' => "Chaque élément doit être sélectionné.",
            'items.*.uuid.exists' => "L'élément sélectionné n'existe pas.",
            'items.*.quantity_to_add.required' => "La quantité à ajouter est obligatoire pour chaque boisson.",
            'items.*.quantity_to_add.integer' => "La quantité doit être un nombre entier.",
            'items.*.quantity_to_add.min' => "La quantité doit être au moins de 1.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $orderUuid)
            ->with('drinks')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $updatedDrinks = [];

            foreach ($request->items as $itemData) {

                $drink = $order->drinks->where('uuid', $itemData['uuid'])->first();

                if (!$drink) continue;

                $allowedStatuses = [
                    OrderMenuRestaurantItemStatus::DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
                ];

                if (!in_array($drink->status, $allowedStatuses, true)) {
                    throw new \Exception(
                        "Impossible d'ajouter de la quantité pour la boisson {$drink->product->name} car son statut actuel est {$drink->status}."
                    );
                }


                $addQty = (int) $itemData['quantity_to_add'];

                // 🔹 Ajouter la quantité
                $drink->quantity_exactly += $addQty;
                $drink->quantity += $addQty;
                $drink->total_price = $drink->quantity_exactly * $drink->unit_price;

                if ($drink->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {
                    $drink->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                } elseif ($drink->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value) {
                    $drink->status = OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                } else {
                    $drink->status = OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                }

                $drink->save();
                $updatedDrinks[] = $drink;
            }

            // 🔹 Rafraîchir statut global de la commande
            $this->refreshOrderStatus($order->fresh());
            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quantités des boissons augmentées avec succès.',
                'items' => $updatedDrinks
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Rafraîchit le statut global d'une commande.
     * @param string $type Type d'items à considérer ('menu' ou 'drink')
     */

    private function refreshOrderStatus(OrderMenuRestaurant $order): void
    {
        $order->load(['items', 'drinks']);

        $allItems = $order->items->merge($order->drinks);

        if ($allItems->isEmpty()) {
            return;
        }

        // 🔹 Dernière action effectuée
        $lastUpdatedItem = $allItems->sortByDesc('updated_at')->first();

        // 🔹 Déterminer le statut basé sur la dernière action
        if ($lastUpdatedItem) {
            switch ($lastUpdatedItem->status) {
                case OrderMenuRestaurantItemStatus::TRANSFERRED->value:
                    $order->status = MenuOrderStatus::TRANSFERRED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value:
                    $order->status = MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value:
                    $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value:
                    $order->status = MenuOrderStatus::TOTAL_DELIVERED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::NEW_REJECTED->value:
                    $order->status = MenuOrderStatus::NEW_REJECTED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::REJECTED->value:
                    $order->status = MenuOrderStatus::REJECTED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value:
                    $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::DELIVERED->value:
                    $order->status = MenuOrderStatus::DELIVERED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::DEFECTIVE->value:
                    $order->status = MenuOrderStatus::DEFECTIVE->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::DEFECTIVE->value:
                    $order->status = MenuOrderStatus::DEFECTIVE->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value:
                    $order->status = MenuOrderStatus::REJECTED_AFTER_VALIDATION->value;
                    $order->save();
                    return;

                case MenuOrderStatus::FACTURE_GENERATE->value:
                    $order->status = MenuOrderStatus::FACTURE_GENERATE->value;
                    $order->save();
                    return;

                case MenuOrderStatus::FACTURATE->value:
                    $order->status = MenuOrderStatus::FACTURATE->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::DELIVERED->value:
                    $order->status = MenuOrderStatus::DELIVERED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value:
                    $order->status = MenuOrderStatus::PARTIAL_DELIVERED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::REINSTATED->value:
                    $order->status = MenuOrderStatus::REINSTATED->value;
                    $order->save();
                    return;

            }
        }

        // 🔹 Tous servis totalement
        $allServed = $allItems->every(
            fn($i) => $i->status === OrderMenuRestaurantItemStatus::DELIVERED->value
        );

        // 🔹 Au moins un servi
        $anyServed = $allItems->some(
            fn($i) => in_array(
                $i->status,
                [
                    OrderMenuRestaurantItemStatus::DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                ]
            )
        );

        // 🔹 Tous prêts pour service
        $allReady = $allItems->every(
            fn($i) => $i->status === OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value
        );

        // 🔹 Au moins un prêt
        $anyReady = $allItems->some(
            fn($i) => $i->status === OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value
        );

        // 🔹 Logique globale (inchangée)
        if ($allServed) {
            $order->status = MenuOrderStatus::DELIVERED->value;
        } elseif ($anyServed) {
            $order->status = MenuOrderStatus::PARTIAL_DELIVERED->value;
        } elseif ($allReady || $anyReady) {
            $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;
        } else {
            $order->status = MenuOrderStatus::IN_PREPARATION->value;
        }
        $order->save();
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::markItemsDefective
     * @permission_desc Mettre les plats d'une commande selectionnées en défectieux
     */
    public function markItemsDefective(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $priorityStatuses = [
            OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
            OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
            OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
        ];

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->with('items')->firstOrFail();

        return DB::transaction(function () use ($validated, $auth, $order, $priorityStatuses) {

            foreach ($validated['items'] as $data) {

                $item = OrderMenuRestaurantItem::where('uuid', $data['uuid'])->with('statuses')->first();

                if (!$item) continue;

                $lastStatus = $item->status;

                if ($lastStatus) {
                   LastStatusItemsMenusRestaurant::updateOrCreate(
                        [
                            'order_menu_restaurant_item_uuid' => $item->uuid,
                        ],
                        [
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'type' => 'menu',
                            'last_status' => $lastStatus,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );
                }

                $qtyToDefect = (int) $data['quantity_to_deliver'];

                $availableQty = $item->statuses()
                    ->whereIn('status', $priorityStatuses)
                    ->sum('quantity');

                if ($qtyToDefect > $availableQty) {
                    throw new \Exception("Quantité insuffisante pour {$item->uuid}");
                }

                $remaining = $qtyToDefect;

                foreach ($priorityStatuses as $status) {

                    if ($remaining <= 0) break;

                    $row = $item->statuses()->where('status', $status)->first();

                    if (!$row || $row->quantity <= 0) continue;
                    $take = min($remaining, $row->quantity);
                    $row->quantity -= $take;
                    $row->save();

                    $defective = $item->statuses()->firstOrCreate(
                        [
                            'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                        ],
                        [
                            'order_menu_restaurant_item_uuid' => $item->uuid,
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'quantity' => 0,
                            'quantity_exactly' => 0,
                            'quantity_accumulated' => 0,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );

                    $defective->quantity += $take;
                    $defective->quantity_exactly = $defective->quantity;
                    $defective->quantity_accumulated += $take;
                    $defective->updated_by = $auth->id;
                    $defective->save();

                    OrderMenuRestaurantDefectiveItem::create([
                        'order_menu_restaurant_item_uuid' => $item->uuid,
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'status' => $status,
                        'quantity' => $take,
                        'reason' => $data['reason'] ?? null,
                        'type' => 'menu',
                        'created_by' => $auth->id,
                    ]);

                    $remaining -= $take;
                }

                $compositions = MenuOrderItem::where('menus_restaurant_uuid', $item->menus_restaurant_uuid)->get();
                foreach ($compositions as $comp) {
                    $qtyToSave = $qtyToDefect * $comp->quantity_used;
                    $virtual = VirtualOrderMenuRestaurant::firstOrCreate(
                        [
                            'item_uuid' => $item->uuid,
                            'orders_menu_restaurant_uuid' => $order->uuid,
                            'product_uuid' => $comp->product_uuid,
                        ],
                        [
                            'quantity_in_defective' => 0,
                            'item_type' => 'menu',
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );
                    $virtual->increment('quantity_in_defective', $qtyToSave);
                }

                $item->update([
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                    'updated_by' => $auth->id,
                ]);
            }

            $this->refreshOrderStatus($order->fresh());

            return response()->json([
                'status' => 'success',
                'message' => 'Items marqués comme défectueux avec priorité.'
            ]);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::restoreDefectiveItems
     * @permission_desc Restaurer les plats d'une commande selectionnées en défectieux
     */
    public function restoreDefectiveItems(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($validated, $auth, $order) {

            foreach ($validated['items'] as $data) {

                $item = OrderMenuRestaurantItem::where('uuid', $data['uuid'])
                    ->with('statuses')
                    ->first();

                if (!$item) continue;

                $qtyToRestore = (int) $data['quantity_to_deliver'];
                $reason = $data['reason'] ?? null;

                /**
                 * 🔥 1. Vérification DEFECTIVE
                 */
                $defectiveRow = $item->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->first();

                if (!$defectiveRow || $qtyToRestore > $defectiveRow->quantity) {
                    throw new \Exception("Quantité DEFECTIVE insuffisante pour {$item->uuid}");
                }

                /**
                 * 🔥 2. Historique (LIFO)
                 */
                $defectHistories = OrderMenuRestaurantDefectiveItem::where('order_menu_restaurant_item_uuid', $item->uuid)
                    ->orderByDesc('created_at')
                    ->get();

                $remaining = $qtyToRestore;

                foreach ($defectHistories as $history) {

                    if ($remaining <= 0) break;

                    $available = (int) $history->quantity;
                    if ($available <= 0) continue;

                    $take = min($remaining, $available);

                    /**
                     * 🔹 3. Restaurer vers status d’origine
                     */
                    $statusRow = $item->statuses()->firstOrCreate(
                        ['status' => $history->status],
                        [
                            'quantity' => 0,
                            'quantity_exactly' => 0,
                            'quantity_accumulated' => 0,
                            'created_by' => $auth->id,
                            'order_menu_restaurant_uuid' => $order->uuid,
                        ]
                    );

                    $statusRow->increment('quantity', $take);
                    $statusRow->increment('quantity_accumulated', $take);
                    $statusRow->update([
                        'quantity_exactly' => $statusRow->quantity,
                        'updated_by' => $auth->id,
                    ]);

                    /**
                     * 🔹 4. Retirer du DEFECTIVE
                     */
                    $defectiveRow->decrement('quantity', $take);

                    /**
                     * 🔹 5. Log de restauration (🔥 IMPORTANT)
                     */
                    OrderMenuRestaurantDefectiveItem::create([
                        'order_menu_restaurant_item_uuid' => $item->uuid,
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'status' => 'restore_'.$history->status, // 🔥 trace claire
                        'quantity' => $take,
                        'reason' => $reason,
                        'type' => 'menu',
                        'created_by' => $auth->id,
                    ]);

                    /**
                     * 🔹 6. Mise à jour historique original
                     */
                    $history->quantity -= $take;

                    if ($history->quantity <= 0) {
                        $history->delete(); // soft delete
                    } else {
                        $history->save();
                    }
                    $remaining -= $take;
                }

                if ($defectiveRow->fresh()->quantity <= 0) {
                    $defectiveRow->update(['quantity_accumulated' => 0]);
                }
                $compositions = MenuOrderItem::where('menus_restaurant_uuid', $item->menus_restaurant_uuid)->get();

                foreach ($compositions as $comp) {

                    $qtyToRestoreStock = $qtyToRestore * $comp->quantity_used;

                    $virtual = VirtualOrderMenuRestaurant::firstOrCreate(
                        [
                            'item_uuid' => $item->uuid,
                            'orders_menu_restaurant_uuid' => $order->uuid,
                            'product_uuid' => $comp->product_uuid,
                        ],
                        [
                            'quantity_in_defective' => 0,
                            'item_type' => 'menu',
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );

                    $virtual->decrement('quantity_in_defective', $qtyToRestoreStock);

                    // 🔥 sécurité anti négatif
                    if ($virtual->fresh()->quantity_in_defective < 0) {
                        $virtual->update(['quantity_in_defective' => 0]);
                    }
                }

                /**
                 * 🔥 9. Recalcul statut global
                 */
                $item->update([
                    'status' => $this->resolveItemStatusFromStatuses($item),
                    'updated_by' => $auth->id,
                ]);
            }

            $this->refreshOrderStatus($order->fresh());

            return response()->json([
                'status' => 'success',
                'message' => 'Quantités restaurées depuis DEFECTIVE avec succès.'
            ]);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::markDrinksDefective
     * @permission_desc Mettre les boissons d'une commande selectionnées en défectieux
     */
    public function markDrinksDefective(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $priorityStatuses = [
            OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
            OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
            OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
        ];

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($validated, $auth, $order, $priorityStatuses) {

            foreach ($validated['items'] as $data) {

                $drink = OrderRestaurantDrink::where('uuid', $data['uuid'])
                    ->with('statuses')
                    ->first();

                if (!$drink) continue;

                // 🔹 sauvegarde dernier statut
                $lastStatus = $drink->status;

                if ($lastStatus) {
                    LastStatusDrinksMenusRestaurant::updateOrCreate(
                        [
                            'order_restaurant_drink_uuid' => $drink->uuid,
                            'type' => 'drink', // 🔥 important
                        ],
                        [
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'product_uuid' => $drink->product_uuid,
                            'last_status' => $lastStatus,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );
                }

                $qtyToDefect = (int) $data['quantity_to_deliver'];

                $availableQty = $drink->statuses()
                    ->whereIn('status', $priorityStatuses)
                    ->sum('quantity');

                if ($qtyToDefect > $availableQty) {
                    throw new \Exception("Quantité insuffisante pour {$drink->uuid}");
                }

                $remaining = $qtyToDefect;

                foreach ($priorityStatuses as $status) {

                    if ($remaining <= 0) break;

                    $row = $drink->statuses()->where('status', $status)->first();

                    if (!$row || $row->quantity <= 0) continue;

                    $take = min($remaining, $row->quantity);

                    $row->quantity -= $take;
                    $row->save();

                    // 🔹 DEFECTIVE status
                    $defective = $drink->statuses()->firstOrCreate(
                        [
                            'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                            'order_restaurant_drink_uuid' => $drink->uuid, // 🔥 important aussi ici
                        ],
                        [
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'product_uuid' => $drink->product_uuid, // ✅ CORRECTION ICI
                            'quantity' => 0,
                            'quantity_exactly' => 0,
                            'quantity_accumulated' => 0,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );

                    $defective->quantity += $take;
                    $defective->quantity_exactly = $defective->quantity;
                    $defective->quantity_accumulated += $take;
                    $defective->updated_by = $auth->id;
                    $defective->save();

                    // 🔹 historique défaut
                    OrderMenuRestaurantDefectiveDrink::create([
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'product_uuid' => $drink->product_uuid,
                        'status' => $status,
                        'quantity' => $take,
                        'reason' => $data['reason'] ?? null,
                        'type' => 'drink',
                        'created_by' => $auth->id,
                    ]);

                    $remaining -= $take;

                    $virtual = VirtualOrderMenuRestaurant::firstOrCreate(
                        [
                            'item_uuid' => $drink->uuid,
                            'orders_menu_restaurant_uuid' => $order->uuid,
                            'product_uuid' => $drink->product_uuid,
                            'item_type' => 'drink',
                            'status' => 'pending'
                        ],
                        [
                            'quantity_in_defective' => 0,
                            'quantity_reserved' => 0,
                            'quantity_exactly' => 0,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );

                    $virtual->increment('quantity_in_defective', $take);
                }

                $drink->update([
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                    'updated_by' => $auth->id,
                ]);
            }

            $this->refreshOrderStatus($order->fresh());

            return response()->json([
                'status' => 'success',
                'message' => 'Boissons marquées comme défectueuses avec priorité.'
            ]);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::deleteDefectiveItems
     * @permission_desc Supprimer les plats d'une commande marqués défectieux
     */
    public function deleteDefectiveItems(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|uuid|exists:orders_menu_restaurant_items,uuid',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 403);
        }

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with('items')
            ->firstOrFail();

        return DB::transaction(function () use ($request, $order, $auth) {

            $warehouse = Warehouse::where('is_used_for_restaurant', true)
                ->lockForUpdate()
                ->firstOrFail();

            $newOrderStatus = LastStatusItemsMenusRestaurant::where('order_menu_restaurant_uuid', $order->uuid)
                ->pluck('last_status')
                ->filter()
                ->first()
                ?? OrderMenuRestaurantItemStatus::TRANSFERRED->value;

            foreach ($request->items as $data) {

                $item = OrderMenuRestaurantItem::where('uuid', $data['uuid'])
                    ->with(['statuses', 'virtuals'])
                    ->lockForUpdate()
                    ->first();

                if (!$item) continue;

                $defective = $item->statuses
                    ->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->first();

                if (!$defective || $defective->quantity <= 0) continue;

                $qty = (int) $defective->quantity;

                foreach ($item->virtuals->where('item_type', 'menu') as $v) {

                    $toDeduct = $v->quantity_in_defective;

                    if ($toDeduct <= 0) continue;

                    $productPoint = ProductPoint::where('produit_uuid', $v->product_uuid)
                        ->where('point_uuid', $warehouse->uuid)
                        ->lockForUpdate()
                        ->first();

                    if (!$productPoint) {
                        throw new \Exception("Stock introuvable pour produit {$v->product_uuid}");
                    }
                    $productPoint->decrement('quantity', $toDeduct);
                    $v->decrement('quantity_in_defective', $toDeduct);
                    $v->decrement('quantity_reserved', $toDeduct);

                    $v->update([
                        'quantity_in_defective' => max(0, $v->quantity_in_defective),
                        'quantity_reserved' => max(0, $v->quantity_reserved),
                    ]);

                    MenuVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
                        ->where('product_uuid', $v->product_uuid)
                        ->update([
                            'quantity_used' => DB::raw("GREATEST(quantity_used - {$toDeduct}, 0)"),
                            'quantity' => DB::raw("GREATEST(quantity - {$qty}, 0)"),
                            'updated_by' => $auth->id,
                        ]);
                }


                $item->update([
                    'quantity_exactly' => max(0, $item->quantity_exactly - $qty),
                    'quantity' => max(0, $item->quantity - $qty),
                    'status' => $newOrderStatus,
                    'updated_by' => $auth->id,
                ]);


                $item->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                    ->update([
                        'quantity_exactly' => DB::raw("GREATEST(quantity_exactly - {$qty}, 0)"),
                        'quantity_accumulated' => DB::raw("GREATEST(quantity_accumulated - {$qty}, 0)"),
                        'updated_by' => $auth->id,
                    ]);

                $defective->delete();

                StatisticsOrderStatusMenuRestaurant::where([
                    'order_menu_restaurant_item_uuid' => $item->uuid,
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value
                ])->delete();


                $hasRemaining = $item->statuses()
                    ->where('status', '!=', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->exists();

                if (!$hasRemaining) {
                    $item->statuses()->delete();
                    $item->delete();
                }
            }

            $order->update([
                'status' => $newOrderStatus,
                'updated_by' => $auth->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Défectueux supprimés + stock restauré correctement.'
            ]);
        });
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::deleteDefectiveDrinks
     * @permission_desc Supprimer les plats d'une commande marqués défectieux
     */
    public function deleteDefectiveDrinks(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 403);
        }

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($request, $order, $auth) {

            $warehouse = Warehouse::where('is_bar_warehouse', true)->lockForUpdate()->firstOrFail();
            $lastStatuses = LastStatusItemsMenusRestaurant::where('order_menu_restaurant_uuid', $order->uuid)->pluck('last_status')->filter()->unique()->values();
            $newOrderStatus = $lastStatuses->first() ?? OrderMenuRestaurantItemStatus::TRANSFERRED->value;

            foreach ($request->items as $data) {

                $drink = OrderRestaurantDrink::where('uuid', $data['uuid'])->with('statuses')->first();

                if (!$drink) continue;

                // 🔹 récupérer DEFECTIVE
                $defective = $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)->first();

                if (!$defective || $defective->quantity <= 0) {
                    continue;
                }

                $qty = (int) $defective->quantity;
                foreach ($drink->virtuals->where('item_type', 'drink') as $v) {
                    $toDeduct = $v->quantity_in_defective;
                    if ($toDeduct <= 0) continue;

                    $productPoint = ProductPoint::where('produit_uuid', $v->product_uuid)
                        ->where('point_uuid', $warehouse->uuid)
                        ->lockForUpdate()
                        ->first();

                    if (!$productPoint) {
                        throw new \Exception("Stock introuvable pour produit {$v->product_uuid}");
                    }
                    $productPoint->decrement('quantity', $toDeduct);

                    $v->decrement('quantity_in_defective', $toDeduct);
                }

                $drink->update([
                    'quantity_exactly' => max(0, $drink->quantity_exactly - $qty),
                    'quantity' => max(0, $drink->quantity - $qty),
                    'status' => $newOrderStatus,
                    'updated_by' => $auth->id,
                ]);

                $drink->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                    ->update([
                        'quantity_exactly' => DB::raw("GREATEST(quantity_exactly - {$qty}, 0)"),
                        'quantity_accumulated' => DB::raw("GREATEST(quantity_accumulated - {$qty}, 0)"),
                        'updated_by' => $auth->id,
                    ]);

                $defective->delete();

                StatisticsOrderStatusDrink::where([
                    'order_restaurant_drink_uuid' => $drink->uuid,
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value
                ])->delete();

                // 🔥 5. CLEAN UP SI PLUS DE STATUT VALIDE
                $hasRemaining = $drink->statuses()
                    ->where('status', '!=', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->exists();

                if (!$hasRemaining) {
                    $drink->statuses()->delete();
                    $drink->delete();
                }

            }

            $order->update([
                'status' => $newOrderStatus,
                'updated_by' => $auth->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Les boissons défectueuses ont été supprimées avec succès.'
            ]);
        });
    }




    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::PrintFactureForOrderMenuRestaurant
     * @permission_desc Imprimer la facture du client
     */
    public static function PrintFactureForOrderMenuRestaurant(Request $request, string $uuid)
    {
        $auth = auth()->user();
        try {
            DB::beginTransaction();

            $order_menu_restaurant = OrderMenuRestaurant::with([
                'restaurantTable',
                'creator',
                'updater',
                'validator',
                'cancelor',
                'partners_restaurant',
                'warehouse',
                'restaurant_room',
                'menu_restaurant',
                'items.menu',
            ])->findOrFail($uuid);

            $fileName   = strtoupper('FACTURE-N°-' . strtoupper($order_menu_restaurant->code) . '-'. '.pdf');
            $folderPath = 'storage/details-facture-orders-menus-restaurant/' . $order_menu_restaurant->uuid;
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                    throw new \RuntimeException("Impossible de créer le répertoire : {$folderPath}");
                }
            }

            $data = ['order' => $order_menu_restaurant];

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.facture-client.facture-client',
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

            $pdf = PdfDocument::where('order_menu_restaurant', $order_menu_restaurant->uuid)
                ->where('name', 'FACTURE-ORDERS-RESTAURANT')
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
                    'name'       => 'FACTURE-ORDERS-RESTAURANT',
                    'order_uuid' => $order_menu_restaurant->uuid,
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

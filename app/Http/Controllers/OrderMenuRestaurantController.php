<?php

namespace App\Http\Controllers;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\TypeClientsForPaiment;
use App\Enums\VirtualOrderMenuRestaurantStatus;
use App\Models\DrinkComposition;
use App\Models\DrinkCompositionItem;
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
use App\Models\RestaurantDrinkConfiguration;
use App\Models\Role;
use App\Models\SettingRestaurant;
use App\Models\StatisticsOrderStatusDrink;
use App\Models\StatisticsOrderStatusMenuRestaurant;
use App\Models\User;
use App\Models\VirtualOrderMenuRestaurant;
use App\Models\Warehouse;
use App\Notifications\OrderNotification;
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
    public function updateActivity(Request $request)
    {
        $request->validate([
            'reservation_uuid' => ['required', 'uuid']
        ]);

        MenuVirtualTemp::where('reservation_uuid', $request->reservation_uuid)
            ->update([
                'last_activity_at' => now()
            ]);

        DrinksVirtualTemp::where('reservation_uuid', $request->reservation_uuid)
            ->update([
                'last_activity_at' => now()
            ]);

        return response()->json([
            'status' => 'success'
        ]);
    }

    public function updateActivityForOrder(Request $request)
    {
        $request->validate([
            'order_menu_restaurant_uuid' => ['required', 'uuid']
        ]);

        $now = now();
        OrderMenuRestaurant::where('uuid', $request->order_menu_restaurant_uuid)
            ->update([
                'editing_started_at' => $now,
                'rollback_at' => null,
            ]);
        return response()->json([
            'status' => 'success'
        ]);
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
            'drink_restaurant_uuid' => ['required', 'uuid'],
        ]);
        $deleted = DrinksVirtualTemp::where('reservation_uuid', $validated['reservation_uuid'])
            ->where('drink_restaurant_uuid', $validated['drink_restaurant_uuid'])
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Boisson supprimée de la réservation',
            'deleted_rows' => $deleted
        ]);
    }

    private function verifyBarStock(array $drinks, string $warehouseDrinkUuid, string $warehouseTransformationUuid, ?string $reservationUuid = null): array {
        $stockErrors = [];

        foreach ($drinks as $drink) {

            $drinkConfig = RestaurantDrinkConfiguration::with('product')
                ->find($drink['drink_restaurant_uuid']);

            if (!$drinkConfig) {
                continue;
            }

            $qty = (float) $drink['quantity'];

            /*
            |--------------------------------------------------------------------------
            | 🍹 DRINK TRANSFORMABLE
            |--------------------------------------------------------------------------
            */
            if ($drinkConfig->is_transformable_product) {

                $composition = DrinkComposition::with('items.product')
                    ->where('drinks_restaurant_uuid', $drinkConfig->uuid)
                    ->first();

                if (!$composition) {
                    $stockErrors[] = [
                        'product_name' => $drinkConfig->drink_name,
                        'quantity_required' => $qty,
                        'quantity_available' => 0,
                        'error' => 'Aucune composition trouvée',
                    ];
                    continue;
                }

                foreach ($composition->items as $item) {

                    $requiredQty = $qty * (float) $item->quantity_used;

                    $realStock = (float) ProductPoint::where(
                        'produit_uuid',
                        $item->product_uuid
                    )
                        ->where(
                            'point_uuid',
                            $warehouseTransformationUuid
                        )
                        ->value('quantity') ?? 0;

                    $reservedStock = DrinksVirtualTemp::where(
                        'product_uuid',
                        $item->product_uuid
                    )
                        ->where('status', 'pending')
                        ->where('type', '!=', 'not_used')

                        ->when($reservationUuid, function ($q) use ($reservationUuid) {
                            $q->where(function ($sub) use ($reservationUuid) {
                                $sub->whereNull('reservation_uuid')
                                    ->orWhere(
                                        'reservation_uuid',
                                        '!=',
                                        $reservationUuid
                                    );
                            });
                        })

                        ->sum('quantity_used');

                    $availableStock =
                        max(0, $realStock - $reservedStock);

                    if ($requiredQty > $availableStock) {
                        $stockErrors[] = [
                            'product_name' =>
                                $item->product?->name ?? 'Inconnu',
                            'quantity_required' =>
                                $requiredQty,
                            'quantity_available' =>
                                $availableStock,
                        ];
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 🥤 DRINK SIMPLE
            |--------------------------------------------------------------------------
            */
            else {

                if (!$drinkConfig->product_uuid) {
                    $stockErrors[] = [
                        'product_name' => $drinkConfig->drink_name,
                        'quantity_required' => $qty,
                        'quantity_available' => 0,
                        'error' => 'Produit introuvable',
                    ];
                    continue;
                }

                $realStock = (float) ProductPoint::where(
                    'produit_uuid',
                    $drinkConfig->product_uuid
                )
                    ->where(
                        'point_uuid',
                        $warehouseDrinkUuid
                    )
                    ->value('quantity') ?? 0;

                $reservedStock = DrinksVirtualTemp::where(
                    'product_uuid',
                    $drinkConfig->product_uuid
                )
                    ->where('status', 'pending')
                    ->where('type', '!=', 'not_used')

                    ->when($reservationUuid, function ($q) use ($reservationUuid) {
                        $q->where(function ($sub) use ($reservationUuid) {
                            $sub->whereNull('reservation_uuid')
                                ->orWhere(
                                    'reservation_uuid',
                                    '!=',
                                    $reservationUuid
                                );
                        });
                    })

                    ->sum('quantity_used');

                $availableStock =
                    max(0, $realStock - $reservedStock);

                if ($qty > $availableStock) {
                    $stockErrors[] = [
                        'product_name' =>
                            $drinkConfig->product?->name
                            ?? $drinkConfig->drink_name,
                        'quantity_required' =>
                            $qty,
                        'quantity_available' =>
                            $availableStock,
                    ];
                }
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

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */
            $validated = $request->validate([
                'reservation_uuid' => ['nullable', 'uuid'],

                'drinks' => ['required', 'array', 'min:1'],

                'drinks.*.drink_restaurant_uuid' => [
                    'required',
                    'uuid',
                    'exists:restaurant_drink_configurations,uuid'
                ],

                'drinks.*.quantity' => [
                    'required',
                    'numeric',
                    'min:1'
                ],
            ]);

            /*
            |--------------------------------------------------------------------------
            | ENTREPÔTS
            |--------------------------------------------------------------------------
            */
            $warehouseDistribution = Warehouse::where(
                'is_bar_warehouse',
                true
            )->firstOrFail();

            $warehouseTransformation = Warehouse::where(
                'is_used_for_drinks_transformation',
                true
            )->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | 🔥 IMPORTANT
            | SI MODIFICATION => ON SUPPRIME LES ANCIENNES RÉSERVATIONS
            |--------------------------------------------------------------------------
            */
            foreach ($validated['drinks'] as $drinkInput) {
                DrinksVirtualTemp::where('reservation_uuid', $reservationUuid)
                    ->where(
                        'drink_restaurant_uuid',
                        $drinkInput['drink_restaurant_uuid']
                    )
                    ->where('status', 'pending')
                    ->delete();
            }

            $stockErrors = [];

            /*
            |--------------------------------------------------------------------------
            | CHECK STOCK
            |--------------------------------------------------------------------------
            */
            foreach ($validated['drinks'] as $drinkInput) {

                $drinkConfig = RestaurantDrinkConfiguration::with('product')
                    ->find($drinkInput['drink_restaurant_uuid']);

                if (!$drinkConfig) {
                    continue;
                }

                $qty = (float) $drinkInput['quantity'];

                /*
                |--------------------------------------------------------------------------
                | 🍹 TRANSFORMABLE
                |--------------------------------------------------------------------------
                */
                if ($drinkConfig->is_transformable_product) {

                    $composition = DrinkComposition::with([
                        'items.product'
                    ])
                        ->where(
                            'drinks_restaurant_uuid',
                            $drinkConfig->uuid
                        )
                        ->first();

                    if (!$composition) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'error' => 'Aucune composition trouvée'
                        ];

                        continue;
                    }

                    foreach ($composition->items as $item) {

                        if (!$item->product_uuid) {
                            continue;
                        }

                        $requiredQty =
                            $qty * (float) $item->quantity_used;

                        /*
                        |--------------------------------------------------------------------------
                        | STOCK DISPONIBLE
                        |--------------------------------------------------------------------------
                        */
                        $available = $this->getDrinkAvailableStock(
                            $item->product_uuid,
                            $warehouseTransformation->uuid,
                            0,
                            $reservationUuid
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | CHECK
                        |--------------------------------------------------------------------------
                        */
                        if ($requiredQty > $available) {

                            $stockErrors[] = [
                                'drink_name' => $drinkConfig->drink_name,
                                'product_name' => $item->product?->name,
                                'quantity_required' => $requiredQty,
                                'quantity_in_stock' => $available,
                                'error' => "Stock insuffisant pour {$item->product?->name}"
                            ];

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | 🔥 NOUVELLE RÉSERVATION
                        |--------------------------------------------------------------------------
                        */
                        DrinksVirtualTemp::create([
                            'reservation_uuid' => $reservationUuid,
                            'drink_restaurant_uuid' => $drinkConfig->uuid,
                            'product_uuid' => $item->product_uuid,
                            'quantity' => $qty,
                            'quantity_used' => $requiredQty,
                            'type' => 'initial',
                            'status' => 'pending',
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                            'last_activity_at' => now()
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | 🍺 SIMPLE
                |--------------------------------------------------------------------------
                */
                else {

                    if (!$drinkConfig->product_uuid) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'error' => 'Produit introuvable'
                        ];

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | STOCK DISPONIBLE
                    |--------------------------------------------------------------------------
                    */
                    $available = $this->getDrinkAvailableStock(
                        $drinkConfig->product_uuid,
                        $warehouseDistribution->uuid,
                        0,
                        $reservationUuid
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK
                    |--------------------------------------------------------------------------
                    */
                    if ($qty > $available) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'product_name' => $drinkConfig->product?->name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => $available,
                            'error' => "Stock insuffisant pour {$drinkConfig->product?->name}"
                        ];

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 🔥 NOUVELLE RÉSERVATION
                    |--------------------------------------------------------------------------
                    */
                    DrinksVirtualTemp::create([
                        'reservation_uuid' => $reservationUuid,
                        'drink_restaurant_uuid' => $drinkConfig->uuid,
                        'product_uuid' => $drinkConfig->product_uuid,
                        'quantity' => $qty,
                        'quantity_used' => $qty,
                        'type' => 'initial',
                        'status' => 'pending',
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'last_activity_at' => now()
                    ]);
                }
            }

            if (!empty($stockErrors)) {

                DB::rollBack();

                return response()->json([
                    'status' => 'error',

                    'message' => collect($stockErrors)
                        ->pluck('error')
                        ->implode(' | '),

                    'details' => $stockErrors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock OK + réservation mise à jour',
                'reservation_uuid' => $reservationUuid,
                'expires_in_minutes' => $this->getLogoutMinutes(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('checkBarStockOnly ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function checkStockOnly(Request $request)
    {
        $auth = auth()->user();

        // 🔴 reservation obligatoire
        if (!$request->filled('reservation_uuid')) {
            return response()->json([
                'status' => 'error',
                'message' => 'reservation_uuid obligatoire'
            ], 422);
        }

        $reservationUuid = $request->reservation_uuid;

        Log::info('CHECK STOCK UUID FINAL', [
            'reservation_uuid' => $reservationUuid
        ]);

        try {

            // 🔹 Validation
            $validated = $request->validate([
                'reservation_uuid' => ['required', 'uuid'],
                'menus' => ['required', 'array', 'min:1'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            Log::info('CHECK STOCK INPUT FULL', [
                'request' => $request->all()
            ]);

            // 🔹 Warehouse
            $warehouseUuid = Warehouse::where('is_used_for_restaurant', true)->value('uuid');

            $menusUuid = collect($validated['menus'])
                ->pluck('menus_restaurant_uuid')
                ->toArray();

            $menuItems = MenuOrderItem::with('product')
                ->whereIn('menus_restaurant_uuid', $menusUuid)
                ->get()
                ->groupBy('menus_restaurant_uuid');

            foreach ($validated['menus'] as $MenusInput) {
                MenuVirtualTemp::where('reservation_uuid', $reservationUuid)
                    ->where(
                        'menus_restaurant_uuid',
                        $MenusInput['menus_restaurant_uuid']
                    )
                    ->where('status', 'pending')
                    ->delete();
            }


            $stockErrors = [];

            // 🔥 TRAITEMENT STOCK + CALCUL
            foreach ($validated['menus'] as $menuInput) {

                $menu = MenuRestaurant::find($menuInput['menus_restaurant_uuid']);
                if (!$menu) continue;

                $menuQuantity = (int) $menuInput['quantity'];

                foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                    $totalUsed = $menuQuantity * $item->quantity_used;

                    try {
                        $this->checkStock(
                            $item->product_uuid,
                            $warehouseUuid,
                            $totalUsed,
                            $reservationUuid
                        );
                    } catch (\Exception $e) {

                        $stockErrors[] = [
                            'menu_name' => $menu->name,
                            'product_name' => $item->product->name ?? 'Inconnu',
                            'quantity_required' => $totalUsed,
                            'quantity_in_stock' => 0,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            // 🔴 ERREUR STOCK
            if (!empty($stockErrors)) {
                return response()->json([
                    'status' => 'error',
                    'message' => collect($stockErrors)
                        ->map(fn($e) => $e['error'])
                        ->implode(' | '),
                    'details' => $stockErrors
                ], 422);
            }


            foreach ($validated['menus'] as $menuInput) {

                $menu = MenuRestaurant::find($menuInput['menus_restaurant_uuid']);
                if (!$menu) continue;

                foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                    $totalUsed = $menuInput['quantity'] * $item->quantity_used;

                    MenuVirtualTemp::create([
                        'reservation_uuid' => $reservationUuid,
                        'menus_restaurant_uuid' => $menuInput['menus_restaurant_uuid'],
                        'product_uuid' => $item->product_uuid,
                        'type' => 'initial',
                        'quantity' => $menuInput['quantity'],
                        'quantity_used' => $totalUsed,
                        'status' => 'pending',
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'last_activity_at' => now()
                    ]);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Stock OK + panier mis à jour',
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
            ], 500);
        }
    }



    private function getDrinkAvailableStock($productUuid, $warehouseUuid, $quantity, $reservationUuid = null, $orderUuid = null) {
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseUuid)
            ->value('quantity') ?? 0;

        $reservedStock = DrinksVirtualTemp::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->where('type', '!=', 'not_used')

            ->when($reservationUuid, function ($q) use ($reservationUuid) {
                $q->where(function ($sub) use ($reservationUuid) {
                    $sub->whereNull('reservation_uuid')
                        ->orWhere('reservation_uuid', '!=', $reservationUuid);
                });
            })

            ->when($orderUuid, function ($q) use ($orderUuid) {
                $q->where(function ($sub) use ($orderUuid) {
                    $sub->whereNull('order_menu_restaurant_uuid')
                        ->orWhere('order_menu_restaurant_uuid', '!=', $orderUuid);
                });
            })

            ->sum('quantity_used');

        $availableStock = max(0, $realStock - $reservedStock);
        if ($quantity > $availableStock) {
            $productName = Product::where('uuid', $productUuid)->value('name')
                ?? 'Produit inconnu';

            throw new \Exception(
                "Stock insuffisant pour « {$productName} ». Disponible : {$availableStock}, Requis : {$quantity}"
            );
        }
        return $availableStock;
    }


    public function forceReleaseStock(Request $request)
    {
        $request->validate(['reservation_uuid' => 'required|uuid']);
        MenuVirtualTemp::where('reservation_uuid', $request->reservation_uuid)->delete();
        DrinksVirtualTemp::where('reservation_uuid', $request->reservation_uuid)->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Stock libéré avec succès'
        ]);
    }
    public function forceReleaseOrderMenuRestaurant(Request $request)
    {
        $request->validate(['order_menu_restaurant_uuid' => 'nullable|uuid']);
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
        $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();

        $warehouseUuid = Warehouse::where('is_used_for_restaurant', true)
            ->value('uuid');
        $stockErrors = [];
        $menusUuid = collect($validated['menus'])->pluck('menus_restaurant_uuid');

        $menuItems = MenuOrderItem::with('product')
            ->whereIn('menus_restaurant_uuid', $menusUuid)
            ->get()
            ->groupBy('menus_restaurant_uuid');

        $MenuUuids = collect($validated['menus'])
            ->pluck('menus_restaurant_uuid')
            ->unique()
            ->toArray();

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where('status', 'pending')
            ->whereIn('menus_restaurant_uuid', $MenuUuids)
            ->delete();

        foreach ($validated['menus'] as $menuInput) {

            foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                $requiredQty = (int) $menuInput['quantity'] * (int) $item->quantity_used;

                $realStock = (float) ProductPoint::where('produit_uuid', $item->product_uuid)
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                $reservedStock = (float) MenuVirtualTemp::where('product_uuid', $item->product_uuid)
                    ->where('status', 'pending')
                    ->where('type', '!=', 'not_used')
                    ->when($orderUuid, function ($q) use ($orderUuid) {
                        $q->where(function ($sub) use ($orderUuid) {
                            $sub->where('order_menu_restaurant_uuid', $orderUuid)
                                ->orWhere(function ($s) use ($orderUuid) {
                                    $s->whereNotNull('order_menu_restaurant_uuid')
                                        ->where('order_menu_restaurant_uuid', '!=', $orderUuid);
                                });
                        });
                    })

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

        foreach ($validated['menus'] as $menuInput) {

            foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {
                MenuVirtualTemp::create([
                    'order_menu_restaurant_uuid' => $orderUuid,
                    'menus_restaurant_uuid' => $menuInput['menus_restaurant_uuid'],
                    'product_uuid' => $item->product_uuid,
                    'type' => 'initial',
                    'reservation_uuid' => $order->reservation_uuid,
                    'quantity' => $menuInput['quantity'],
                    'quantity_used' => $menuInput['quantity'] * $item->quantity_used,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'status' => 'pending',
                    'last_activity_at' => now(),
                ]);
            }
        }

        $order->update([
            'is_in_editing' => true,
            'editing_by' => $auth->id,
            'editing_started_at' => now(),
            'rollback_at' => null
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stock OK pour modification commande',
            'expires_in_minutes' => $this->getLogoutMinutes(),

        ]);
    }


    public function appendDrinksToOrder(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {

            $validated = $request->validate([
                'order_menu_restaurant_uuid' => ['required', 'uuid'],
                'drinks' => ['required', 'array', 'min:1'],
                'drinks.*.drink_restaurant_uuid' => ['required', 'uuid', 'exists:restaurant_drink_configurations,uuid'],
                'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            $orderUuid = $validated['order_menu_restaurant_uuid'];

            $warehouseDistribution = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $warehouseTransformation = Warehouse::where('is_used_for_drinks_transformation', true)->firstOrFail();
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();
            $reservationUuid = $order->reservation_uuid;

            $stockErrors = [];

            foreach ($validated['drinks'] as $drinkInput) {

                $drinkConfig = RestaurantDrinkConfiguration::with('product')
                    ->find($drinkInput['drink_restaurant_uuid']);

                if (!$drinkConfig) {
                    continue;
                }

                $qty = (float) $drinkInput['quantity'];

                if ($drinkConfig->is_transformable_product) {

                    $composition = DrinkComposition::with('items.product')
                        ->where(
                            'drinks_restaurant_uuid',
                            $drinkConfig->uuid
                        )
                        ->first();

                    if (!$composition || $composition->items->isEmpty()) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => 0,
                            'error' => 'Aucune composition trouvée'
                        ];

                        continue;
                    }

                    foreach ($composition->items as $item) {

                        if (!$item->product_uuid) {
                            continue;
                        }

                        $requiredQty =
                            $qty * (float) $item->quantity_used;

                        /*
                        |--------------------------------------------------------------------------
                        | STOCK DISPONIBLE
                        |--------------------------------------------------------------------------
                        */
                        $available = $this->getDrinkAvailableStock(
                            $item->product_uuid,
                            $warehouseTransformation->uuid,
                            0,
                            null,
                            $orderUuid
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | CHECK
                        |--------------------------------------------------------------------------
                        */
                        if ($requiredQty > $available) {

                            $stockErrors[] = [
                                'drink_name' => $drinkConfig->drink_name,
                                'product_name' => $item->product?->name,
                                'quantity_required' => $requiredQty,
                                'quantity_in_stock' => $available,
                                'error' => "{$item->product?->name} : demandé {$requiredQty}, disponible {$available}",
                            ];

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | 💾 SAVE TEMP
                        |--------------------------------------------------------------------------
                        */
                        DrinksVirtualTemp::create([
                            'reservation_uuid' => $reservationUuid,
                            'order_menu_restaurant_uuid' => $orderUuid,
                            'drink_restaurant_uuid' => $drinkConfig->uuid,
                            'product_uuid' => $item->product_uuid,
                            'quantity' => $qty,
                            'quantity_used' => $requiredQty,
                            'type' => 'editing',
                            'status' => 'pending',
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                            'last_activity_at' => now()
                        ]);
                    }

                }

                /*
                |--------------------------------------------------------------------------
                | 🍺 SIMPLE
                |--------------------------------------------------------------------------
                */
                else {

                    if (!$drinkConfig->product_uuid) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => 0,
                            'error' => 'Produit introuvable'
                        ];

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | STOCK DISPONIBLE
                    |--------------------------------------------------------------------------
                    */
                    $available = $this->getDrinkAvailableStock(
                        $drinkConfig->product_uuid,
                        $warehouseDistribution->uuid,
                        0,
                        null,
                        $orderUuid
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK
                    |--------------------------------------------------------------------------
                    */
                    if ($qty > $available) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'product_name' => $drinkConfig->product?->name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => $available,
                            'error' => "{$drinkConfig->drink_name} : demandé {$qty}, disponible {$available}",
                        ];

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 💾 SAVE TEMP
                    |--------------------------------------------------------------------------
                    */
                    DrinksVirtualTemp::create([
                        'reservation_uuid' => $reservationUuid,
                        'order_menu_restaurant_uuid' => $orderUuid,
                        'drink_restaurant_uuid' => $drinkConfig->uuid,
                        'product_uuid' => $drinkConfig->product_uuid,
                        'quantity' => $qty,
                        'quantity_used' => $qty,
                        'type' => 'editing',
                        'status' => 'pending',
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'last_activity_at' => now()
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | ERRORS
            |--------------------------------------------------------------------------
            */
            if (!empty($stockErrors)) {

                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'message' => collect($stockErrors)
                        ->pluck('error')
                        ->implode(' | '),
                    'details' => $stockErrors,
                ], 422);
            }
            $order->update(['is_in_editing' => true, 'editing_by' => $auth->id, 'editing_started_at' => now(),'rollback_at' => null, ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock boissons OK pour commande',
                'expires_in_minutes' => $this->getLogoutMinutes(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('checkDrinksStockByOrder ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }


    public function appendMenusToOrder(Request $request)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'order_menu_restaurant_uuid' => ['required', 'uuid'],
            'menus' => ['required', 'array', 'min:1'],
            'menus.*.menus_restaurant_uuid' => ['required', 'uuid'],
            'menus.*.quantity' => ['required', 'numeric', 'min:1'],
        ]);

        $orderUuid = $validated['order_menu_restaurant_uuid'];
        $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();
        $reservationUuid = $order->reservation_uuid;

        $warehouseUuid = Warehouse::where('is_used_for_restaurant', true)
            ->value('uuid');

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

                $reservedStock = (float) MenuVirtualTemp::where('product_uuid', $item->product_uuid)
                    ->where('status', 'pending')
                    ->where('type', '!=', 'not_used')

                    // 🔥 exclure uniquement CETTE commande en modification
                    ->when($orderUuid, function ($q) use ($orderUuid) {
                        $q->where(function ($sub) use ($orderUuid) {
                            $sub->whereNull('order_menu_restaurant_uuid')
                                ->orWhere('order_menu_restaurant_uuid', '!=', $orderUuid);
                        });
                    })

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

        foreach ($validated['menus'] as $menuInput) {

            foreach ($menuItems[$menuInput['menus_restaurant_uuid']] ?? [] as $item) {

                MenuVirtualTemp::create([
                    'reservation_uuid' => $reservationUuid,
                    'order_menu_restaurant_uuid' => $orderUuid,
                    'menus_restaurant_uuid' => $menuInput['menus_restaurant_uuid'],
                    'product_uuid' => $item->product_uuid,
                    'type' => 'initial',
                    'quantity' => $menuInput['quantity'],
                    'quantity_used' => $menuInput['quantity'] * $item->quantity_used,
                    'status' => 'pending',
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'last_activity_at' => now()
                ]);
            }
        }
        $order->update(['is_in_editing' => true, 'editing_by' => $auth->id, 'editing_started_at' => now(),'rollback_at' => null, ]);
        return response()->json([
            'status' => 'success',
            'message' => 'Stock OK pour modification commande',
            'expires_in_minutes' => $this->getLogoutMinutes(),

        ]);
    }


    public function checkDrinksStockByOrder(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {

            $validated = $request->validate([
                'order_menu_restaurant_uuid' => ['required', 'uuid'],

                'drinks' => ['required', 'array', 'min:1'],

                'drinks.*.drink_restaurant_uuid' => [
                    'required',
                    'uuid',
                    'exists:restaurant_drink_configurations,uuid'
                ],

                'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            $orderUuid = $validated['order_menu_restaurant_uuid'];
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | ENTREPÔTS
            |--------------------------------------------------------------------------
            */
            $warehouseDistribution = Warehouse::where(
                'is_bar_warehouse',
                true
            )->firstOrFail();

            $warehouseTransformation = Warehouse::where(
                'is_used_for_drinks_transformation',
                true
            )->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | 🔥 DRINKS ENVOYÉS
            |--------------------------------------------------------------------------
            */
            $drinkUuids = collect($validated['drinks'])
                ->pluck('drink_restaurant_uuid')
                ->unique()
                ->toArray();

            DrinksVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
                ->where('status', 'pending')
                ->whereIn('drink_restaurant_uuid', $drinkUuids)
                ->delete();


            $stockErrors = [];

            /*
            |--------------------------------------------------------------------------
            | CHECK STOCK
            |--------------------------------------------------------------------------
            */
            foreach ($validated['drinks'] as $drinkInput) {

                $drinkConfig = RestaurantDrinkConfiguration::with('product')
                    ->find($drinkInput['drink_restaurant_uuid']);

                if (!$drinkConfig) {
                    continue;
                }

                $qty = (float) $drinkInput['quantity'];

                /*
                |--------------------------------------------------------------------------
                | 🍹 TRANSFORMABLE
                |--------------------------------------------------------------------------
                */
                if ($drinkConfig->is_transformable_product) {

                    $composition = DrinkComposition::with('items.product')
                        ->where(
                            'drinks_restaurant_uuid',
                            $drinkConfig->uuid
                        )
                        ->first();

                    if (!$composition || $composition->items->isEmpty()) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => 0,
                            'error' => 'Aucune composition trouvée'
                        ];

                        continue;
                    }

                    foreach ($composition->items as $item) {

                        if (!$item->product_uuid) {
                            continue;
                        }

                        $requiredQty =
                            $qty * (float) $item->quantity_used;

                        /*
                        |--------------------------------------------------------------------------
                        | STOCK DISPONIBLE
                        |--------------------------------------------------------------------------
                        */
                        $available = $this->getDrinkAvailableStock(
                            $item->product_uuid,
                            $warehouseTransformation->uuid,
                            0,
                            null,
                            $orderUuid
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | CHECK
                        |--------------------------------------------------------------------------
                        */
                        if ($requiredQty > $available) {

                            $stockErrors[] = [
                                'drink_name' => $drinkConfig->drink_name,
                                'product_name' => $item->product?->name,
                                'quantity_required' => $requiredQty,
                                'quantity_in_stock' => $available,
                                'error' => "{$item->product?->name} : demandé {$requiredQty}, disponible {$available}",
                            ];

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | 💾 SAVE TEMP
                        |--------------------------------------------------------------------------
                        */
                        DrinksVirtualTemp::create([
                            'reservation_uuid' => $order->reservation_uuid,
                            'order_menu_restaurant_uuid' => $orderUuid,
                            'drink_restaurant_uuid' => $drinkConfig->uuid,
                            'product_uuid' => $item->product_uuid,
                            'quantity' => $qty,
                            'quantity_used' => $requiredQty,
                            'type' => 'editing',
                            'status' => 'pending',
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                            'last_activity_at' => now(),
                        ]);
                    }

                }

                /*
                |--------------------------------------------------------------------------
                | 🍺 SIMPLE
                |--------------------------------------------------------------------------
                */
                else {

                    if (!$drinkConfig->product_uuid) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => 0,
                            'error' => 'Produit introuvable'
                        ];

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | STOCK DISPONIBLE
                    |--------------------------------------------------------------------------
                    */
                    $available = $this->getDrinkAvailableStock(
                        $drinkConfig->product_uuid,
                        $warehouseDistribution->uuid,
                        0,
                        null,
                        $orderUuid
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK
                    |--------------------------------------------------------------------------
                    */
                    if ($qty > $available) {

                        $stockErrors[] = [
                            'drink_name' => $drinkConfig->drink_name,
                            'product_name' => $drinkConfig->product?->name,
                            'quantity_required' => $qty,
                            'quantity_in_stock' => $available,
                            'error' => "{$drinkConfig->drink_name} : demandé {$qty}, disponible {$available}",
                        ];

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 💾 SAVE TEMP
                    |--------------------------------------------------------------------------
                    */
                    DrinksVirtualTemp::create([
                        'order_menu_restaurant_uuid' => $orderUuid,
                        'reservation_uuid' => $order->reservation_uuid,
                        'drink_restaurant_uuid' => $drinkConfig->uuid,
                        'product_uuid' => $drinkConfig->product_uuid,
                        'quantity' => $qty,
                        'quantity_used' => $qty,
                        'type' => 'editing',
                        'status' => 'pending',
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'last_activity_at' => now()
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | ERRORS
            |--------------------------------------------------------------------------
            */
            if (!empty($stockErrors)) {

                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'message' => collect($stockErrors)
                        ->pluck('error')
                        ->implode(' | '),
                    'details' => $stockErrors,
                ], 422);
            }

            $order->update([
                'is_in_editing' => true,
                'editing_by' => $auth->id,
                'editing_started_at' => now(),
                'rollback_at' => null,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock boissons OK pour commande',
                'expires_in_minutes' => $this->getLogoutMinutes(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('checkDrinksStockByOrder ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function checkStock(
        $productUuid,
        $warehouseUuid,
        $quantity,
        $orderUuid = null,
        $reservationUuid = null
    ) {

        // 🔥 Stock réel
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseUuid)
            ->value('quantity') ?? 0;

        $reservedStock = (float) MenuVirtualTemp::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->where('type', '!=', 'not_used')
            ->when($reservationUuid, function ($q) use ($reservationUuid) {
                $q->where(function ($sub) use ($reservationUuid) {
                    $sub->where('reservation_uuid', $reservationUuid)
                        ->orWhere(function ($s) use ($reservationUuid) {
                            $s->whereNotNull('reservation_uuid')
                                ->where('reservation_uuid', '!=', $reservationUuid);
                        });
                });
            })

            ->sum('quantity_used');




        $availableStock = max(0, $realStock - $reservedStock);

        if ($availableStock < 0) {
            $availableStock = 0;
        }

        // 🔥 Vérification
        if ($quantity > $availableStock) {

            $productName = Product::where('uuid', $productUuid)
                ->value('name') ?? 'Produit inconnu';

            throw new \Exception(
                "Stock insuffisant pour « {$productName} ». Disponible : {$availableStock}, Requis : {$quantity}"
            );
        }

        return $availableStock;
    }



    public function reserveStock($orderUuid, $itemUuid, $itemType, $productUuid, $quantity, $auth, $warehouseUuid, $quantityUsed) {
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseUuid)
            ->value('quantity') ?? 0;

        $reservedStock = (float) VirtualOrderMenuRestaurant::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->where('orders_menu_restaurant_uuid', '!=', $orderUuid)
            ->sum('quantity_reserved');

        $availableStock = max(0, $realStock - $reservedStock);

        if ($quantity > $availableStock) {

            $productName = Product::where('uuid', $productUuid)->value('name')
                ?? 'Produit inconnu';

            throw new \Exception(
                "Stock insuffisant pour « {$productName} ». Disponible : {$availableStock}, Requis : {$quantity}"
            );
        }

        VirtualOrderMenuRestaurant::create([
            'orders_menu_restaurant_uuid' => $orderUuid,
            'item_uuid' => $itemUuid,
            'item_type' => $itemType,
            'product_uuid' => $productUuid,
            'quantity' => $quantity,
            'quantity_reserved' => $quantityUsed,
            'quantity_exactly' => $quantityUsed,
            'quantity_delivered_exactly' => 0,
            'status' => 'pending',
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_last_items' => true
        ]);
    }
    public function releaseStock($orderUuid, $itemUuid, $itemType, $productUuid, $quantity, $auth, $warehouseUuid)
    {
        // 🔹 On récupère les réservations en attente
        $reservations = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
            ->where('item_uuid', $itemUuid)
            ->where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        $remainingToRelease = $quantity;

        foreach ($reservations as $reservation) {

            if ($remainingToRelease <= 0) break;

            $deduct = min($reservation->quantity_reserved, $remainingToRelease);

            $reservation->quantity_reserved -= $deduct;
            $reservation->updated_by = $auth->id;

            if ($reservation->quantity_reserved <= 0) {
                $reservation->delete();
            } else {
                $reservation->save();
            }

            $remainingToRelease -= $deduct;
        }
    }
    private function reserveDrinkStock($orderUuid, $drinkOrderUuid, $productUuid, $quantity, $auth, $warehouseUuid, $quantityUsed) {
        $realStock = (float) ProductPoint::where('produit_uuid', $productUuid)
            ->where('point_uuid', $warehouseUuid)
            ->value('quantity') ?? 0;

        $reservedStock = (float) VirtualOrderMenuRestaurant::where('product_uuid', $productUuid)
            ->where('status', 'pending')
            ->where('item_type', 'drink')
            ->sum('quantity_reserved');

        $availableStock = max(0, $realStock - $reservedStock);

        if ($quantityUsed > $availableStock) {
            $productName = Product::where('uuid', $productUuid)->value('name') ?? 'Produit inconnu';

            throw new \Exception(
                "Stock insuffisant pour « {$productName} ». Disponible : {$availableStock}, Requis : {$quantityUsed}"
            );
        }

        VirtualOrderMenuRestaurant::create([
            'orders_menu_restaurant_uuid' => $orderUuid,
            'item_type' => 'drink',
            'item_uuid' => $drinkOrderUuid,
            'product_uuid' => $productUuid,
            'quantity_reserved' => $quantityUsed,
            'quantity_exactly' => $quantityUsed,
            'quantity_delivered_exactly' => 0,
            'quantity' => $quantity,
            'status' => 'pending',
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_last_items' => true,
        ]);
    }
    public function cancelRervationsAfterValidation(Request $request)
    {
        $validated = $request->validate([
            'order_menu_restaurant_uuid' => ['nullable', 'uuid'],
        ]);

        $orderUuid = $validated['order_menu_restaurant_uuid'];
        $order = OrderMenuRestaurant::where('uuid', $orderUuid)->firstOrFail();

        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where(function ($query) {
                $query->whereIn('type', ['initial', 'editing','not_used'])
                    ->orWhereNull('reservation_uuid');
            })
            ->delete();

        $virtualItems = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
            ->where('status', 'pending')
            ->where('item_type', 'menu')
            ->get();

        $ItemMenu = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $orderUuid) ->get();
        foreach ($virtualItems as $item) {
            $menuItem = $ItemMenu->firstWhere('uuid', $item->item_uuid);
            if (!$menuItem) continue;
            MenuVirtualTemp::create([
                'order_menu_restaurant_uuid' => $orderUuid,
                'reservation_uuid' => $order->reservation_uuid,
                'menus_restaurant_uuid' => $menuItem->menus_restaurant_uuid,
                'product_uuid' => $item->product_uuid,
                'type' => 'initial',
                'quantity' => $item->quantity,
                'quantity_used' => $item->quantity_exactly,
                'status' => 'pending',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'last_activity_at' => now()
            ]);
        }

        DrinksVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
            ->where(function ($query) {
                $query->whereIn('type', ['initial', 'editing'])
                    ->orWhereNull('reservation_uuid');
            })
            ->delete();

        $virtualItemsDrinks = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
            ->where('status', 'pending')
            ->where('item_type', 'drink')
            ->get();

        $itemDrinks = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $orderUuid)->get();
        foreach ($virtualItemsDrinks as $item) {
            $realDrink = $itemDrinks->firstWhere('uuid', $item->item_uuid);
            if (!$realDrink) continue;
            DrinksVirtualTemp::create([
                'order_menu_restaurant_uuid' => $orderUuid,
                'reservation_uuid' => $order->reservation_uuid,
                'product_uuid' => $item->product_uuid,
                'drink_restaurant_uuid' => $realDrink->drink_restaurant_uuid,
                'type' => 'initial',
                'quantity' => $item->quantity,
                'quantity_used' => $item->quantity_exactly,
                'status' => 'pending',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'last_activity_at' => now()
            ]);
        }

        OrderMenuRestaurant::where('uuid', $orderUuid)
            ->update([
                'is_in_editing' => false,
                'editing_by' => null,
                'editing_started_at' => null,
            ]);
        return response()->json([
            'status' => 'success',
            'message' => 'Modifications annulées, retour à l’état initial'
        ]);
    }
    public function cancelCurrentRervations(Request $request)
    {
        $validated = $request->validate([
            'reservation_uuid' => ['nullable', 'uuid'],
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

    public function removeAbandonedReservation(Request $request)
    {
        $validated = $request->validate([
            'reservation_uuid' => ['required', 'uuid'],
        ]);

        $reservationUuid = $validated['reservation_uuid'];

        MenuVirtualTemp::where('reservation_uuid', $reservationUuid)
            ->where('type', 'initial')
            ->whereNull('order_menu_restaurant_uuid')
            ->delete();

        DrinksVirtualTemp::where('reservation_uuid', $reservationUuid)
            ->where('type', 'initial')
            ->whereNull('order_menu_restaurant_uuid')
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Réservation temporaire supprimée'
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

                'menus' => ['nullable', 'array', 'required_without:drinks'],
                'menus.*.menus_restaurant_uuid' => ['required_with:menus', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required_with:menus', 'numeric', 'min:1'],
                'menus.*.unit_price' => ['nullable', 'numeric', 'min:0'],

                'remise' => ['nullable', 'numeric', 'min:0'],
                'full_name' => ['nullable', 'string', 'max:255'],

                'drinks' => ['nullable', 'array', 'required_without:menus'],
                'drinks.*.drink_restaurant_uuid' => ['required_with:drinks', 'uuid', 'exists:restaurant_drink_configurations,uuid'],
                'drinks.*.quantity' => ['required_with:drinks', 'numeric', 'min:1'],
                'drinks.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $warehouseUuid = $warehouse->uuid;

            $warehouseDrinks = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $warehouseDrinkUuid = $warehouseDrinks->uuid;

            $warehouseTransformation = Warehouse::where('is_used_for_drinks_transformation', true)->firstOrFail();
            $warehouseTransformationUuid = $warehouseTransformation->uuid;

            Log::info($warehouseUuid);
            Log::info($warehouseDrinkUuid);
            Log::info($warehouseTransformationUuid);

            if (!$warehouse || !$warehouseDrinks || !$warehouseTransformation) {
                throw new \Exception("Configuration des entrepôts incomplète");
            }

            // ✅ Vérification stock
            if (!empty($validated['menus'])) {
                $errors = $this->verifyMenuStock(
                    $validated['menus'],
                    $warehouseUuid
                );
                if (!empty($errors)) {
                    $message = collect($errors)
                        ->map(function ($e) {
                            return "{$e['menu_name']} : demandé {$e['quantity_required']}, disponible {$e['quantity_available']}";
                        })
                        ->implode(' | ');
                    return response()->json([
                        'status' => 'error',
                        'message' => $message,
                        'details' => $errors
                    ], 422);
                }
            }

            if (!empty($validated['drinks'])) {
                $errors = $this->verifyBarStock(
                    $validated['drinks'],
                    $warehouseDrinkUuid,
                    $warehouseTransformationUuid,
                    $validated['reservation_uuid'] ?? null
                );
                if (!empty($errors)) {
                    $message = collect($errors)
                        ->map(fn($e) =>
                        "{$e['product_name']} : demandé {$e['quantity_required']}, disponible {$e['quantity_available']}"
                        )
                        ->implode(' | ');
                    return response()->json([
                        'status' => 'error',
                        'message' => $message,
                        'details' => $errors
                    ], 422);
                }
            }

            // 5. Création de la commande principale
            $order = OrderMenuRestaurant::create([
                'status' => \App\Enums\MenuOrderStatus::TRANSFERRED->value,
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
            /*
            |--------------------------------------------------------------------------
            | 🍽️ TRAITEMENT DES MENUS (Si présents)
            |--------------------------------------------------------------------------
            */
            if (!empty($validated['menus']) && is_array($validated['menus'])) {

                // 1. Vérification du stock spécifique aux menus
                if ($errors = $this->verifyMenuStock($validated['menus'], $warehouseUuid)) {
                    $message = collect($errors)->map(function ($e) {
                        return "{$e['menu_name']} : demandé {$e['quantity_required']}, disponible {$e['quantity_available']}";
                    })->implode(' | ');

                    return response()->json([
                        'status' => 'error',
                        'message' => $message,
                        'details' => $errors
                    ], 422);
                }

                // 2. Création des items
                foreach ($validated['menus'] as $mInput) {
                    $menu = MenuRestaurant::where('uuid', $mInput['menus_restaurant_uuid'])->first();

                    if (!$menu) continue; // Sécurité si menu non trouvé

                    $isFree = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;
                    $unitPrice = $mInput['unit_price'] ?? $menu->price ?? 0;
                    $totalPrice = $isFree ? 0 : ($unitPrice * $mInput['quantity']);

                    // Création de l'item de commande
                    $orderItem = OrderMenuRestaurantItem::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'menus_restaurant_uuid'      => $menu->uuid,
                        'quantity'                   => $mInput['quantity'],
                        'quantity_exactly'           => $mInput['quantity'],
                        'unit_price'                 => $unitPrice,
                        'total_price'                => $totalPrice,
                        'is_free'                    => $isFree,
                        'status'                     => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'created_by'                 => $auth->id,
                        'updated_by'                 => $auth->id,
                        'is_last_items'              => true
                    ]);

                    // Historique des statuts
                    OrderMenuItemStatus::create([
                        'order_menu_restaurant_item_uuid' => $orderItem->uuid,
                        'order_menu_restaurant_uuid'      => $order->uuid,
                        'status'                          => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'quantity'                        => $orderItem->quantity,
                        'quantity_exactly'                => $orderItem->quantity,
                        'quantity_accumulated'            => $orderItem->quantity,
                        'created_by'                      => $auth->id,
                        'updated_by'                      => $auth->id,
                    ]);

                    // Statistiques
                    StatisticsOrderStatusMenuRestaurant::create([
                        'order_menu_restaurant_item_uuid' => $orderItem->uuid,
                        'order_menu_restaurant_uuid'      => $order->uuid,
                        'status'                          => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'quantity'                        => $orderItem->quantity,
                        'created_by'                      => $auth->id,
                        'updated_by'                      => $auth->id,
                        'transferred_at'                  => now(),
                        'make_transferred_by'             => $auth->id,
                    ]);

                    // Réserve virtuelle basée sur la composition du menu
                    $compositions = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
                    foreach ($compositions as $comp) {
                        $requiredQty = $mInput['quantity'] * $comp->quantity_used;
                        $this->reserveStock(
                            $order->uuid,
                            $orderItem->uuid,
                            'menu',
                            $comp->product_uuid,
                            $mInput['quantity'],
                            $auth,
                            $warehouseUuid,
                            $requiredQty
                        );
                    }
                }
            }

            // 7. Enregistrement des Boissons (Commande + Réserve Virtuelle)

            if (!empty($validated['drinks'])) {

                foreach ($validated['drinks'] as $drinkInput) {

                    $drinkConfig = RestaurantDrinkConfiguration::with(['product'])
                        ->find($drinkInput['drink_restaurant_uuid']);

                    /*
                    |--------------------------------------------------------------------------
                    | 🚫 CONFIG INTROUVABLE
                    |--------------------------------------------------------------------------
                    */

                    if (!$drinkConfig) {

                        Log::warning('Drink config introuvable', [
                            'drink_restaurant_uuid' => $drinkInput['drink_restaurant_uuid']
                        ]);

                        continue;
                    }

                    $quantity = (float) ($drinkInput['quantity'] ?? 0);
                    $uPrice   = (float) ($drinkInput['unit_price'] ?? 0);

                    /*
                    |--------------------------------------------------------------------------
                    | 🚫 QUANTITÉ INVALIDE
                    |--------------------------------------------------------------------------
                    */

                    if ($quantity <= 0) {

                        Log::warning('Quantité invalide pour drink', [
                            'drink_uuid' => $drinkConfig->uuid,
                            'quantity' => $quantity
                        ]);

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 🍹 CREATE ORDER DRINK
                    |--------------------------------------------------------------------------
                    */

                    $drinkOrder = OrderRestaurantDrink::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'drink_restaurant_uuid' => $drinkConfig->uuid,
                        'quantity' => $quantity,
                        'quantity_exactly' => $quantity,
                        'unit_price' => $uPrice,
                        'total_price' => $uPrice * $quantity,
                        'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_last_items' => true,
                    ]);

                    OrderMenuItemStatusForDrink::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                        'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        'quantity' => $quantity,
                        'quantity_exactly' => $quantity,
                        'quantity_accumulated' => $quantity,

                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]);


                    if ($drinkConfig->is_transformable_product) {

                        $composition = DrinkComposition::with(['items.product'])
                            ->where('drinks_restaurant_uuid', $drinkConfig->uuid)
                            ->first();

                        if (!$composition || $composition->items->isEmpty()) {

                            Log::warning('Drink sans composition', [
                                'drink_uuid' => $drinkConfig->uuid
                            ]);

                            continue;
                        }

                        StatisticsOrderStatusDrink::create([
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                            'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                            'quantity' => $quantity,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                            'transferred_at' => now(),
                            'make_transferred_by' => $auth->id,
                        ]);

                        foreach ($composition->items as $item) {

                            if (empty($item->product_uuid)) {

                                Log::warning('Composition item sans product_uuid', [
                                    'drink_uuid' => $drinkConfig->uuid,
                                    'item_uuid' => $item->uuid ?? null,
                                ]);

                                continue;
                            }

                            $quantityUsed = $quantity * (float) $item->quantity_used;
                            $this->reserveDrinkStock(
                                orderUuid: $order->uuid,
                                drinkOrderUuid: $drinkOrder->uuid,
                                productUuid: $item->product_uuid,
                                quantity: $quantity,
                                auth: $auth,
                                warehouseUuid: $warehouseTransformationUuid,
                                quantityUsed: $quantityUsed
                            );
                        }

                    }

                    else {

                        if (empty($drinkConfig->product_uuid)) {

                            Log::warning('Drink simple sans product_uuid', [
                                'drink_uuid' => $drinkConfig->uuid
                            ]);
                            continue;
                        }

                        $quantityUsed = $quantity;
                        StatisticsOrderStatusDrink::create([
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                            'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                            'quantity' => $quantityUsed,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                            'transferred_at' => now(),
                            'make_transferred_by' => $auth->id,
                        ]);

                        $this->reserveDrinkStock(
                            orderUuid: $order->uuid,
                            drinkOrderUuid: $drinkOrder->uuid,
                            productUuid: $drinkConfig->product_uuid,
                            quantity: $quantity,
                            auth: $auth,
                            warehouseUuid: $warehouseDrinkUuid,
                            quantityUsed: $quantityUsed
                        );
                    }
                }
            }

            $hasMenus = !empty($validated['menus']);
            $hasDrinks = !empty($validated['drinks']);

            if ($hasMenus || $hasDrinks) {
                $target = match (true) {
                    $hasMenus && $hasDrinks => 'all',
                    $hasMenus => 'kitchen',
                    $hasDrinks => 'bar',
                    default => 'all',
                };

                $message = match (true) {
                    $hasMenus && $hasDrinks => "Commande cuisine + bar {$order->code} enregistrée.",
                    $hasMenus => "Commande cuisine {$order->code} enregistrée.",
                    $hasDrinks => "Commande bar {$order->code} enregistrée.",
                    default => null,
                };

                \App\Models\OrderNotification::createOrUpdateNotification(
                    $order->uuid,
                    MenuOrderStatus::TRANSFERRED->value,
                    $message,
                    $auth->id,
                    $target
                );
            }

            $reservationUuid = $request->reservation_uuid;
            $affectedMenus = \DB::table('menu_virtuals_temp')
                ->where('reservation_uuid', $reservationUuid)
                ->whereNull('order_menu_restaurant_uuid') // 🔥 important
                ->update([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'updated_by' => $auth->id,
                    'updated_at' => now(),
                ]);

            $affectedDrinks = \DB::table('drinks_virtuals_temp')
                ->where('reservation_uuid', $reservationUuid)
                ->whereNull('order_menu_restaurant_uuid') // 🔥 important
                ->update([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'updated_by' => $auth->id,
                    'updated_at' => now(),
                ]);


            $cuisinierRole = Role::where('name', 'CUISINIER')->first();
            $barmanRole = Role::where('name', 'BARMAN')->first();

            if ($cuisinierRole || $barmanRole) {
                if ($cuisinierRole && $hasMenus) {
                    $firstCuisinier = $cuisinierRole->first();
                    if ($firstCuisinier) {
                        $order->update([
                            'status' => MenuOrderStatus::TRANSFERRED->value,
                            'transfered_at' => now(),
                            'transfered_by' => $auth->id,
                            'kitchen_user_id' => $firstCuisinier->id,
                        ]);
                    }
                }

                if ($barmanRole && $hasDrinks) {
                    $firstBarman = $barmanRole->first();
                    if ($firstBarman) {
                        $order->update([
                            'status' => MenuOrderStatus::TRANSFERRED->value,
                            'transfered_at' => now(),
                            'transfered_by' => $auth->id,
                            'bar_user_id' => $firstBarman->id,
                        ]);
                    }
                }
            }


                DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Commande enregistrée et transférée avec succès',
                'order_uuid' => $order->uuid
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Erreur Store Order:', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $message = $e->getMessage();

            if (str_contains($message, 'Stock insuffisant')) {
                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 422);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur serveur, veuillez réessayer.',
            ], 500);
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
            'drinks' => ['nullable', 'array', 'required_without:menus'],
            'drinks.*.drink_restaurant_uuid' => ['required', 'uuid', 'exists:restaurant_drink_configurations,uuid'],
            'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
        ]);

        foreach ($validated['drinks'] as $d) {

            $drinkConfig = RestaurantDrinkConfiguration::findOrFail($d['drink_restaurant_uuid']);
            $existingDrink = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)
                ->where('drink_restaurant_uuid', $d['drink_restaurant_uuid'])
                ->first();

            if (!$existingDrink) {
                continue;
            }

            \Log::info('=== DEBUG REDUCTION DRINK ===');

            $statuses = OrderMenuItemStatusForDrink::where('order_restaurant_drink_uuid', $existingDrink->uuid)->get();

            $newQty = (int) $d['quantity'];

            $oldQty = (int) $existingDrink->quantity_exactly;

            $qtyRejected = $statuses->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)
                ->sum('quantity');

            $qtyTransferred = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                ->sum('quantity');

            $editableQty = $qtyRejected + $qtyTransferred;

            $qtyToRemove = max(0, $oldQty - $newQty);
            if ($editableQty <= 0 && $qtyToRemove > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' =>
                        "Aucune quantité disponible à réduire pour \"{$drinkConfig->drink_name}\".",
                ], 422);
            }

            if ($qtyToRemove > 0 && $qtyToRemove > $editableQty) {

                \Log::warning(
                    '❌ BLOQUÉ DRINK - dépassement autorisé'
                );
                return response()->json([
                    'status' => 'error',
                    'message' =>
                        "Impossible de supprimer {$qtyToRemove} \"{$drinkConfig->drink_name}\". Maximum autorisé : {$editableQty}.",
                ], 422);
            }

            \Log::info('✅ PASSÉ DRINK');

            // 🔴 Cas DELIVERED
            if (
                $existingDrink->status ===
                OrderMenuRestaurantItemStatus::DELIVERED->value
            ) {

                if (
                    $newQty < $existingDrink->quantity
                ) {

                    return response()->json([
                        'status' => 'error',
                        'message' =>
                            "Réduction impossible : \"{$drinkConfig->drink_name}\" est déjà servi. Vous ne pouvez qu'augmenter la quantité.",
                    ], 422);
                }
            }
        }

        return response()->json([
            'status' => 'success'
        ]);
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
                'type_clients_for_payment' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
                'restaurant_table_uuid' => ['nullable','uuid','required_if:type_clients_for_payment,' . ConsumptionType::DINE_IN->value, 'exists:restaurant_tables,uuid'],
                'order_menu_restaurant_date' => ['required', 'date_format:Y-m-d H:i:s'],
                'consumption_type' => ['required', 'string', new Enum(ConsumptionType::class)],
                'partners_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value, 'exists:restaurant_partners,uuid'],
                'free_client_for_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::FREE->value, 'exists:free_clients_restaurants,uuid'],
                'warehouse_uuid' => ['nullable', 'uuid', 'exists:warehouses,uuid'],
                'restaurant_room_uuid' => ['nullable', 'uuid', 'exists:restaurant_rooms,uuid'],

                'remise' => ['nullable', 'numeric', 'min:0'],
                'full_name' => ['nullable', 'string', 'max:255'],

                'menus' => ['nullable', 'array'],
                'menus.*.menus_restaurant_uuid' => ['required_with:menus', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required_with:menus', 'numeric', 'min:0'],
                'menus.*.unit_price' => ['nullable', 'numeric', 'min:0'],

                'drinks' => ['nullable', 'array', 'required_without:menus'],
                'drinks.*.drink_restaurant_uuid' => ['required', 'uuid', 'exists:restaurant_drink_configurations,uuid'],
                'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
                'drinks.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            $order->update([
                'type_clients_for_payment' => $validated['type_clients_for_payment'],
                'consumption_type' => $validated['consumption_type'],
                'restaurant_table_uuid' => $validated['restaurant_table_uuid'] ?? null,
                'partners_restaurant_uuid' => $validated['partners_restaurant_uuid'] ?? null,
                'restaurant_room_uuid' => $validated['restaurant_room_uuid'] ?? null,
                'free_client_for_restaurant_uuid' => $validated['free_client_for_restaurant_uuid'] ?? null,
                'order_menu_restaurant_date' => $validated['order_menu_restaurant_date'],
                'remise' => $validated['remise'] ?? 0,
                'full_name' => $validated['full_name'] ?? null,
                'full_name_for_client_free' => $validated['full_name_for_client_free'] ?? null,
                'updated_by' => $auth->id,
            ]);


            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $warehouseUuid = $warehouse->uuid;

            $warehouseDrinks = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $warehouseDrinkUuid = $warehouseDrinks->uuid;

            $warehouseTransformation = Warehouse::where('is_used_for_drinks_transformation', true)->firstOrFail();
            $warehouseTransformationUuid = $warehouseTransformation->uuid;


            if (!$warehouse || !$warehouseDrinks || !$warehouseTransformation) {
                throw new \Exception("Configuration des entrepôts incomplète");
            }


            $menus = $validated['menus'] ?? [];
            if (!empty($menus)) {
                foreach ($menus as $m) {
                    $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);

                    $unitPrice = $m['unit_price'] ?? $menu->price ?? 0;
                    $isLastItem = $m['is_last_items'] ?? false;

                    if ($isLastItem) {
                        continue;
                    }

                    $existingItem = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->where('menus_restaurant_uuid', $menu->uuid)->first();

                    /*
                    |--------------------------------------------------------------------------
                    | CAS 1 : ITEM EXISTE
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

                        $isPartialCompletedOrReaday = in_array($existingItem->status, [
                            OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                            OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value,
                        ]);

                        $isTransferred = $existingItem->status === OrderMenuRestaurantItemStatus::TRANSFERRED->value;
                        $isInPreparation = $existingItem->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                        $isDelivered = $existingItem->status === OrderMenuRestaurantItemStatus::DELIVERED->value;

                        if ($newQty == $oldQty && !$isRejectedGroup && !$isDelivered && !$isTransferred && !$isInPreparation) {
                            continue;
                        }

                        if ($isRejectedGroup) {
                            $this->handleRejected($existingItem, $m, $menu, $order, $unitPrice, $auth);
                            continue;
                        }

                        if ($isPartialCompletedOrReaday) {
                            $this->handleQuantityUpdate($existingItem, $m, $menu, $order, $unitPrice, $auth);
                            continue;
                        }

                        if ($isTransferred) {
                            $this->handleTransferred($existingItem, $m, $menu, $order, $unitPrice, $auth);
                            continue;
                        }

                        if ($isInPreparation) {
                            $this->handleInPreparation($existingItem, $m, $menu, $order, $unitPrice, $auth);
                            continue;
                        }

                        if ($isDelivered) {
                            $this->handleDeliveredOrPartial($existingItem, $m, $menu, $order, $unitPrice, $auth);
                            continue;
                        }

                        $this->updateExistingMenuItem($existingItem, $menu, $order, $newQty, $unitPrice, $auth, $warehouseUuid);

                        continue;
                    }
                    $this->createNewMenuItem($m, $menu, $order, $unitPrice, $auth);
                }
            }

            $drinks = $validated['drinks'] ?? [];

            if (!empty($drinks)) {

                foreach ($drinks as $d) {

                    if ($d['is_last_items'] ?? false) {
                        continue;
                    }

                    $unitPrice = $d['unit_price'] ?? 0;

                    // 🔥 NEW MODEL
                    $drinkConfig = RestaurantDrinkConfiguration::findOrFail(
                        $d['drink_restaurant_uuid']
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | EXISTE DÉJÀ
                    |--------------------------------------------------------------------------
                    */
                    $existingDrink = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)->where('drink_restaurant_uuid', $drinkConfig->uuid)->first();

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

                        $isPartialCompletedOrReadyDrinks = in_array($existingDrink->status, [
                            OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                            OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value,
                        ]);

                        $isTransferredDrinks = $existingDrink->status === OrderMenuRestaurantItemStatus::TRANSFERRED->value;
                        $isInPreparationDrinks = $existingDrink->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value;
                        $isDeliveredDrinks = $existingDrink->status === OrderMenuRestaurantItemStatus::DELIVERED->value;

                        if (
                            $newQty == $oldQty &&
                            !$isRejectedGroupDrinks &&
                            !$isPartialCompletedOrReadyDrinks &&
                            !$isTransferredDrinks &&
                            !$isInPreparationDrinks &&
                            !$isDeliveredDrinks
                        ) {
                            continue;
                        }

                        if ($isRejectedGroupDrinks) {
                            $this->handleRejectedDrink($existingDrink, $d, $unitPrice, $auth, $order);
                            continue;
                        }

                        if ($isPartialCompletedOrReadyDrinks) {
                            $this->handleQuantityUpdateDrink($existingDrink, $d, $unitPrice, $auth, $order);
                            continue;
                        }

                        if ($isTransferredDrinks) {
                            $this->handleTransferredDrink($existingDrink, $d, $unitPrice, $auth, $order);
                            continue;
                        }

                        if ($isInPreparationDrinks) {
                            $this->handleInPreparationDrink($existingDrink, $d, $unitPrice, $auth, $order);
                            continue;
                        }

                        if ($isDeliveredDrinks) {
                            $this->handleDeliveredOrPartialDrink($existingDrink, $d, $unitPrice, $auth, $order);
                            continue;
                        }

                        $this->updateExistingDrink($existingDrink, $d, $drinkConfig, $unitPrice, $auth, $warehouseDrinkUuid
                        );

                        continue;
                    }

                    $this->createNewDrink($d, $order, $drinkConfig, $unitPrice, $auth, $warehouseDrinkUuid, $warehouseTransformationUuid);
                }
            }

            $order->update([
                'updated_by' => $auth->id,
                'is_in_editing' => false,
                'editing_by' => $auth->id,
                'editing_started_at' => null,
                'rollback_at' => null
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
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l’ajout des éléments : ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Helper central la gestion des boissons par statut
     */
    private function resolveDrinkStatusFromStatuses(OrderRestaurantDrink $drink): string
    {
        $requiredQty = (int) $drink->quantity_exactly;

        // 🔹 récupérer toutes les quantités en 1 seule requête
        $allStatuses = $drink->statuses()
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('status')
            ->map(fn($rows) => (int) $rows->sum('quantity'))
            ->toArray();

        $statuses = collect($allStatuses)
            ->only(OrderMenuRestaurantItemStatus::priorityList())
            ->filter(fn($qty) => $qty > 0)
            ->toArray();

        $finalStatus = $this->weightedRandomWithConditions(
            $statuses,
            $allStatuses,
            $requiredQty
        );
        return $finalStatus;
    }
    private function computeDeliveryDrinksStatus(OrderRestaurantDrink $drink): ?string
    {
        $drink->refresh();

        $deliveredQty = (int) $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');

        $requiredQty = (int) $drink->quantity_exactly;

        if ($requiredQty > 0 && $deliveredQty === $requiredQty) {
            return OrderMenuRestaurantItemStatus::DELIVERED->value;
        }
        if ($deliveredQty > 0) {
            return OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
        }
        return null;
    }
    private function resolveItemDrinksStatus(OrderRestaurantDrink $drink, int $diff, int $newQty, $auth)
    {
        $drink->refresh();

        if ($diff > 0) {
            return OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }

        if ($diff < 0) {
            $deliveredQty = (int) $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
                ->whereNull('deleted_at')->sum('quantity');

            if ($newQty > 0 && $deliveredQty === $newQty) {
                return OrderMenuRestaurantItemStatus::DELIVERED->value;
            }
            $status = $this->resolveDrinkStatusFromStatuses($drink);
            $this->notifyStatusDrinkChange($drink, $status);
            return $status;
        }

        $status = $this->computeDeliveryDrinksStatus($drink)
            ?? $this->resolveDrinkStatusFromStatuses($drink);
        $this->notifyStatusDrinkChange($drink, $status);
        return $status;
    }
    private function notifyStatusDrinkChange(OrderRestaurantDrink $drink, string $status): void
    {
        $order = $drink->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $status,
            "Commande {$order->code} déjà en " . OrderMenuRestaurantItemStatus::safeLabel($status) . ".",
            auth()->id(),
            $drink->uuid,
            'bar'
        );
    }
    private function updateVirtualDrinkStock($order, $drink, $diffQuantity, $auth)
    {
        $barWarehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
        $transformationWarehouse = Warehouse::where('is_used_for_drinks_transformation', true)->firstOrFail();

        $drinkConfig = RestaurantDrinkConfiguration::where('uuid', $drink->drink_restaurant_uuid)->first();
        if (!$drinkConfig) return;

        $finalQuantity = (float) $drink->quantity_exactly;

        /**
         * 1. NETTOYAGE TOTAL POUR CETTE LIGNE
         * On enlève TOUT (editing, initial, pending) pour éviter les restes
         */
        VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
            ->where('item_uuid', $drink->uuid)
            ->delete();

        DrinksVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
            ->where('drink_restaurant_uuid', $drink->drink_restaurant_uuid)
            ->delete();

        /**
         * 2. CAS BOISSON COMPOSÉE
         */
        if ($drinkConfig->is_transformable_product) {
            $composition = DrinkComposition::with('items.product')
                ->where('drinks_restaurant_uuid', $drinkConfig->uuid)
                ->first();

            if (!$composition) {
                throw new \Exception("Composition introuvable pour {$drinkConfig->drink_name}");
            }

            foreach ($composition->items as $item) {
                if (!$item->product_uuid) continue;

                $totalQtyUsed = $finalQuantity * (float) $item->quantity_used;

                $this->reserveDrinkStock(
                    orderUuid: $order->uuid,
                    drinkOrderUuid: $drink->uuid,
                    productUuid: $item->product_uuid,
                    quantity: $finalQuantity,
                    auth: $auth,
                    warehouseUuid: $transformationWarehouse->uuid,
                    quantityUsed: $totalQtyUsed,
                );

                DrinksVirtualTemp::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'reservation_uuid'           => $order->reservation_uuid,
                    'drink_restaurant_uuid'      => $drink->drink_restaurant_uuid,
                    'product_uuid'               => $item->product_uuid,
                    'quantity'                   => $finalQuantity,
                    'quantity_used'              => $totalQtyUsed,
                    'type'                       => 'initial',
                    'status'                     => 'pending',
                    'created_by'                 => $auth->id,
                    'updated_by'                 => $auth->id,
                ]);
            }
        }
        /**
         * 3. CAS BOISSON SIMPLE
         */
        else {
            $productUuid = $drinkConfig->product_uuid ?? $drink->product_uuid;

            $this->reserveDrinkStock(
                orderUuid: $order->uuid,
                drinkOrderUuid: $drink->uuid,
                productUuid: $productUuid,
                quantity: $finalQuantity,
                auth: $auth,
                warehouseUuid: $barWarehouse->uuid,
                quantityUsed: $finalQuantity,
            );

            DrinksVirtualTemp::create([
                'order_menu_restaurant_uuid' => $order->uuid,
                'reservation_uuid'           => $order->reservation_uuid,
                'drink_restaurant_uuid'      => $drink->drink_restaurant_uuid,
                'product_uuid'               => $productUuid,
                'quantity'                   => $finalQuantity,
                'quantity_used'              => $finalQuantity,
                'type'                       => 'initial',
                'status'                     => 'pending',
                'created_by'                 => $auth->id,
                'updated_by'                 => $auth->id,
            ]);
        }
    }
    private function handleDeliveredOrPartialDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity_exactly;

        if ($newQty === $oldQty) {
            return null;
        }

        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire. Vous ne pouvez que augmenter la quantité.",
            ], 422);
        }

        $diff = $newQty - $oldQty;

        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);
        $newStatus = $this->resolveItemDrinksStatus($drink, $diff, $newQty, $auth);
        $drink->update([
            'status' => $newStatus,
        ]);
        $order = $drink->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "{$order->code}La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'bar'
        );
        $this->refreshOrderStatus($order);
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
                $this->reserveDrinkStock($drink->order_menu_restaurant_uuid, $drink->uuid, $product->uuid, $diffQty, $auth, $warehouseDrinkUuid,$newQty);
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
    private function createNewDrink(array $d, OrderMenuRestaurant $order, RestaurantDrinkConfiguration $drinkConfig, float $unitPrice, $auth, string $warehouseDrinkUuid, string $warehouseTransformationUuid): OrderRestaurantDrink {
        $quantity = (float) ($d['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw new \Exception("Quantité invalide pour le drink");
        }

        $drinkOrder = OrderRestaurantDrink::create([
            'order_menu_restaurant_uuid' => $order->uuid,
            'drink_restaurant_uuid' => $drinkConfig->uuid,
            'quantity' => $quantity,
            'quantity_exactly' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
            'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_new_items' => true,
            'is_last_items' => true,
        ]);

        OrderMenuItemStatusForDrink::create([
            'order_menu_restaurant_uuid' => $order->uuid,
            'order_restaurant_drink_uuid' => $drinkOrder->uuid,
            'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity' => $quantity,
            'quantity_exactly' => $quantity,
            'quantity_accumulated' => $quantity,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);

        if ($drinkConfig->is_transformable_product) {

            $composition = DrinkComposition::with(['items.product'])
                ->where('drinks_restaurant_uuid', $drinkConfig->uuid)
                ->first();

            if (!$composition || $composition->items->isEmpty()) {
                throw new \Exception("Composition introuvable pour ce drink");
            }

            StatisticsOrderStatusDrink::create([
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'quantity' => $quantity,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'transferred_at' => now(),
                'make_transferred_by' => $auth->id,
            ]);

            foreach ($composition->items as $item) {

                if (empty($item->product_uuid)) {
                    continue;
                }

                $quantityUsed = $quantity * (float) $item->quantity_used;

                $this->reserveDrinkStock(
                    orderUuid: $order->uuid,
                    drinkOrderUuid: $drinkOrder->uuid,
                    productUuid: $item->product_uuid,
                    quantity: $quantity,
                    auth: $auth,
                    warehouseUuid: $warehouseTransformationUuid,
                    quantityUsed: $quantityUsed
                );
            }

        } else {

            if (empty($drinkConfig->product_uuid)) {
                throw new \Exception("Produit introuvable pour ce drink simple");
            }

            StatisticsOrderStatusDrink::create([
                'order_menu_restaurant_uuid' => $order->uuid,
                'order_restaurant_drink_uuid' => $drinkOrder->uuid,
                'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'quantity' => $quantity,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'transferred_at' => now(),
                'make_transferred_by' => $auth->id,
            ]);

            $this->reserveDrinkStock(
                orderUuid: $order->uuid,
                drinkOrderUuid: $drinkOrder->uuid,
                productUuid: $drinkConfig->product_uuid,
                quantity: $quantity,
                auth: $auth,
                warehouseUuid: $warehouseDrinkUuid,
                quantityUsed: $quantity
            );
        }
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            MenuOrderStatus::TRANSFERRED->value,
            "Boisson(s) ajoutée à la commande {$order->code} et transmise au bar.",
            $auth->id,
            'bar'
        );
        $order->update([
            'status' => MenuOrderStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        $barmanRole = Role::where('name', 'BARMAN')->first();
        if ($barmanRole) {
            $firstBarman = User::whereHas('roles', function ($q) use ($barmanRole) {
                $q->where('roles.id', $barmanRole->id);
            })->first();

            if ($firstBarman) {
                $order->update([
                    'transfered_at' => now(),
                    'transfered_by' => $auth->id,
                    'bar_user_id' => $firstBarman->id,
                ]);
            }
        }
        return $drinkOrder;
    }
    private function handleRejectedDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth, OrderMenuRestaurant $order) {
        $newQtyRequested = (int) $data['quantity'];
        $oldTotalQty = (int) $drink->quantity_exactly;

        if ($newQtyRequested === $oldTotalQty) {
            return null;
        }

        $statuses = $drink->statuses;

        $qtyRejected = $statuses->whereIn('status', OrderMenuRestaurantItemStatus::REJECTED->value)->sum('quantity');

        $qtyTransferred = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)->sum('quantity');

        $totalMutable = $qtyRejected + $qtyTransferred;
        $diff = $newQtyRequested - $oldTotalQty;

        DB::transaction(function () use ($drink, $newQtyRequested, $oldTotalQty, $totalMutable, $auth, $order) {

            if ($newQtyRequested < $oldTotalQty) {
                $qtyToRemove = $oldTotalQty - $newQtyRequested;
                if ($qtyToRemove > $totalMutable) {
                    throw new \Exception(
                        "Action impossible. Vous ne pouvez réduire que {$totalMutable} quantité(s) rejetée(s) ou transférée(s)."
                    );
                }
                $this->removeQuantitiesDrink($drink, $qtyToRemove);
            }

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

        $drink->update([
            'quantity' => $newQtyRequested,
            'quantity_exactly' => $newQtyRequested,
            'total_price' => $newQtyRequested * $unitPrice,
            'is_rejected' => false,
            'updated_by' => $auth->id,
        ]);

        $newStatus = $this->resolveItemDrinksStatus($drink, $diff, $newQtyRequested, $auth);
        $drink->update([
            'status' => $newStatus,
        ]);
        $order = $drink->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'bar'
        );
        $this->refreshOrderStatus($order);

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


        $drink->update([
            'quantity'         => $newTotalQty,
            'quantity_exactly' => $newTotalQty,
            'total_price'      => $unitPrice * $newTotalQty,
            'updated_by'       => $auth->id,
        ]);

        $newStatus = $this->resolveItemDrinksStatus($drink, $diff, $newTotalQty, $auth);
        $drink->update([
            'status' => $newStatus,
        ]);
        $order = $drink->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "{$order->code}La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'bar'
        );
        $this->refreshOrderStatus($order);

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


        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);

        $newStatus = $this->resolveItemDrinksStatus($drink, $diff, $newQty, $auth);
        $drink->update([
            'status' => $newStatus,
        ]);
        $order = $drink->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'bar'
        );
        $this->refreshOrderStatus($order);

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
        $oldQty = (int) $drink->quantity_exactly;

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

        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);

        $newStatus = $this->resolveItemDrinksStatus($drink, $diff, $newQty, $auth);

        $drink->update([
            'status' => $newStatus,
        ]);
        $order = $drink->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'bar'
        );
        $this->refreshOrderStatus($order);

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

    private function computeDeliveryStatus(OrderMenuRestaurantItem $item): ?string
    {
        $item->refresh();

        $deliveredQty = (int) $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');

        $requiredQty = (int) $item->quantity_exactly;

        if ($requiredQty > 0 && $deliveredQty === $requiredQty) {
            return OrderMenuRestaurantItemStatus::DELIVERED->value;
        }

        if ($deliveredQty > 0) {
            return OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
        }

        // 🔥 IMPORTANT : pas de fallback ici
        return null;
    }
    private function resolveItemStatusFromStatuses(OrderMenuRestaurantItem $item): string
    {
        $requiredQty = (int) $item->quantity_exactly;

        // 🔹 récupérer toutes les quantités en 1 seule requête
        $allStatuses = $item->statuses()
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('status')
            ->map(fn($rows) => (int) $rows->sum('quantity'))
            ->toArray();

        $statuses = collect($allStatuses)
            ->only(OrderMenuRestaurantItemStatus::priorityList())
            ->filter(fn($qty) => $qty > 0)
            ->toArray();

        $finalStatus = $this->weightedRandomWithConditions(
            $statuses,
            $allStatuses,
            $requiredQty
        );

        return $finalStatus;
    }
    private function weightedRandomWithConditions(array $statuses, array $allStatuses, int $requiredQty): string
    {
        while (!empty($statuses)) {

            $picked = $this->pickByMaxWithPriority($statuses);

            // 🔥 TOTAL_DELIVERED
            if ($picked === OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value) {
                $qty = $allStatuses[$picked] ?? 0;
                if ($qty === $requiredQty && $requiredQty > 0) {
                    return $picked;
                }
                if ($qty > 0) {
                    return OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
                }
            }
            if ($picked === OrderMenuRestaurantItemStatus::DELIVERED->value) {

                $qty = $allStatuses[$picked] ?? 0;

                if ($qty === $requiredQty && $requiredQty > 0) {
                    return $picked;
                }
                if ($qty > 0) {
                    return OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                }
            }

            if (!in_array($picked, [
                OrderMenuRestaurantItemStatus::DELIVERED->value,
                OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
            ], true)) {
                return $picked;
            }
            unset($statuses[$picked]);
        }

        return OrderMenuRestaurantItemStatus::TRANSFERRED->value;
    }
    private function pickByMaxWithPriority(array $statuses): string
    {
        $maxQty = max($statuses);
        $candidates = array_keys(
            array_filter($statuses, fn($qty) => $qty === $maxQty)
        );
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        foreach (OrderMenuRestaurantItemStatus::priorityList() as $priority) {
            if (in_array($priority, $candidates, true)) {
                return $priority;
            }
        }
        return $candidates[0];
    }
    private function updateExistingMenuItem(OrderMenuRestaurantItem $item, MenuRestaurant $menu, OrderMenuRestaurant $order, int $newQty, float $unitPrice, $auth, $warehouseUuid) {
        $oldQty = (int) $item->quantity_exactly;

        if ($newQty === $oldQty) {
            return $item;
        }

        $diffQty = $newQty - $oldQty;

        /**
         * 🔥 RECALCUL STATUT (NE PAS FORCER TRANSFERRED)
         */
        $deliveredQty = (int) $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');

        if ($deliveredQty === $newQty && $newQty > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::DELIVERED->value;
        } elseif ($deliveredQty > 0) {
            $newStatus = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
        } else {
            $newStatus = OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }

        /**
         * 🔹 UPDATE ITEM
         */
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);

        /**
         * 🔄 GESTION STOCK
         */
        if ($diffQty !== 0) {

            $components = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();

            foreach ($components as $comp) {

                $qty = $diffQty * $comp->quantity_used;

                if ($diffQty > 0) {
                    // 🔺 AUGMENTATION → réservation
                    $this->reserveStock(
                        $order->uuid,
                        $item->uuid,
                        'menu',
                        $comp->product_uuid,
                        $qty,
                        $auth,
                        $warehouseUuid,
                        $qty
                    );
                } else {
                    // 🔻 DIMINUTION → libération
                    $this->releaseStock(
                        $order->uuid,
                        $item->uuid,
                        'menu',
                        $comp->product_uuid,
                        abs($qty),
                        $auth,
                        $warehouseUuid
                    );
                }
            }
        }

        return $item;
    }
    private function createNewMenuItem(array $m, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
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
        $warehouse = Warehouse::where('is_used_for_restaurant', true)->first();
        $compositions = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
        foreach ($compositions as $comp) {
            $requiredQty = $m['quantity'] * $comp->quantity_used;
            $this->reserveStock(
                $order->uuid,
                $item->uuid,
                'menu',
                $comp->product_uuid,
                $m['quantity'],
                $auth,
                $warehouse->uuid,
                $requiredQty,
            );
        }
        OrderMenuItemStatus::create([
            'order_menu_restaurant_item_uuid' => $item->uuid,
            'order_menu_restaurant_uuid'      => $order->uuid,
            'status'                          => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity'                        => $item->quantity,
            'quantity_exactly'                => $item->quantity,
            'quantity_accumulated'            => $item->quantity,
            'created_by'                      => $auth->id,
            'updated_by'                      => $auth->id,
        ]);
        StatisticsOrderStatusMenuRestaurant::create([
            'order_menu_restaurant_item_uuid' => $item->uuid,
            'order_menu_restaurant_uuid'      => $order->uuid,
            'status'                          => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'quantity'                        => $item->quantity,
            'created_by'                      => $auth->id,
            'updated_by'                      => $auth->id,
            'transferred_at'                  => now(),
            'make_transferred_by'             => $auth->id,
        ]);
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            MenuOrderStatus::TRANSFERRED->value,
            "Menu(s) ajouté à la commande {$order->code} et transmis en cuisine.",
            $auth->id,
            'kitchen'
        );
        $order->update([
            'status' => MenuOrderStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);
        $cuisinierRole = Role::where('name', 'CUISINIER')->first();
        if ($cuisinierRole) {
            $firstCuisinier = User::whereHas('roles', function ($q) use ($cuisinierRole) {
                $q->where('roles.id', $cuisinierRole->id);
            })->first();
            if ($firstCuisinier) {
                $order->update([
                    'transfered_at' => now(),
                    'transfered_by' => $auth->id,
                    'kitchen_user_id' => $firstCuisinier->id,
                ]);
            }
        }
        return $item;
    }
    private function resolveItemStatus(OrderMenuRestaurantItem $item, int $diff, int $newQty, $auth)
    {
        $item->refresh();

        if ($diff > 0) {
            return OrderMenuRestaurantItemStatus::TRANSFERRED->value;
        }

        if ($diff < 0) {
            $deliveredQty = (int) $item->statuses()->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
                ->whereNull('deleted_at')->sum('quantity');

            if ($newQty > 0 && $deliveredQty === $newQty) {
                return OrderMenuRestaurantItemStatus::DELIVERED->value;
            }
            $status = $this->resolveItemStatusFromStatuses($item);
            $this->notifyStatusChange($item, $status);
            return $status;
        }

        $status = $this->computeDeliveryStatus($item)
            ?? $this->resolveItemStatusFromStatuses($item);

        $this->notifyStatusChange($item, $status);

        return $status;
    }
    private function notifyStatusChange(OrderMenuRestaurantItem $item, string $status): void
    {
        $order = $item->order;

        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $status,
            "Commande {$order->code} déjà en " . OrderMenuRestaurantItemStatus::safeLabel($status) . ".",
            auth()->id(),
            $item->uuid
        );
    }
    private function updateVirtualStock($menu, $order, $item, $diffQuantity, $auth)
    {
        $components = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
        $warehouse = Warehouse::where('is_used_for_restaurant', true)->first();

        $finalQuantity = (int) $item->quantity_exactly;

        foreach ($components as $comp) {

            $qtyPerUnit = $comp->quantity_used;

            // 🔥 quantité totale requise pour ce item
            $totalQtyUsed = $finalQuantity * $qtyPerUnit;

            // 🔥 delta (uniquement si modification)
            $qtyDelta = $diffQuantity * $qtyPerUnit;

            $virtualEntry = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
                ->where('item_uuid', $item->uuid)
                ->where('status', 'pending')
                ->where('product_uuid', $comp->product_uuid)
                ->first();

            if ($virtualEntry) {

                // 🔥 CAS UPDATE (on recalcule proprement, PAS increment)
                $virtualEntry->update([
                    'quantity_reserved' => $totalQtyUsed,
                    'quantity_exactly' => $finalQuantity,
                    'quantity' => $finalQuantity,
                    'updated_by' => $auth->id,
                ]);

            } else {

                // 🔥 CAS CREATE (nouvel item)
                if ($qtyDelta > 0) {
                    $this->reserveStock(
                        $order->uuid,
                        $item->uuid,
                        'menu',
                        $comp->product_uuid,
                        $totalQtyUsed,
                        $auth,
                        $warehouse->uuid,
                        $finalQuantity
                    );
                }
            }

            // 🔥 synchro temp table
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
                    'updated_by' => $auth->id,
                    'created_by' => $auth->id,
                ]
            );
        }
    }
    private function handleRejected(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $item->quantity_exactly;

        if ($newQty === $oldQty) {
            return;
        }

        $statuses = $item->statuses;

        $qtyRejected = $statuses->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)->sum('quantity');

        $qtyTransferred = $statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)->sum('quantity');

        $totalMutable = $qtyRejected + $qtyTransferred;

        $diff = $newQty - $oldQty;

        DB::transaction(function () use ($item, $newQty, $oldQty, $totalMutable, $auth, $order, $diff) {

            /**
             * 🔥 CAS 1 : RÉDUCTION
             */
            if ($diff < 0) {
                $qtyToRemove = abs($diff);

                if ($qtyToRemove > $totalMutable) {
                    throw new \Exception("Réduction impossible. Max autorisé: {$totalMutable}");
                }
                $this->removeQuantities($item, $qtyToRemove);
            }

            /**
             * 🔥 CAS 2 : AUGMENTATION => TRANSFERRED OBLIGATOIRE
             */
            if ($diff > 0) {
                $this->incrementStatusWithHistory(
                    $item,
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    $diff,
                    $auth,
                    $order,
                    $newQty
                );
            }
        });

        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $newQty * $unitPrice,
            'is_rejected' => false,
            'updated_by' => $auth->id,
        ]);
        $newStatus = $this->resolveItemStatus($item, $diff, $newQty, $auth);

        $item->update([
            'status' => $newStatus,
        ]);
        $order = $item->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'kitchen'
        );
        $this->refreshOrderStatus($order);

        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => $newStatus
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => abs($diff),
                'rejected_at' => now(),
                'make_rejected_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }
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
    private function handleDeliveredOrPartial(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $item->quantity_exactly;

        if ($newQty === $oldQty) {
            return null;
        }

        /**
         * ❌ INTERDICTION DE DIMINUER
         */
        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$menu->name}\" déjà servie.",
            ], 422);
        }

        $diff = $newQty - $oldQty;

        /**
         * 🔹 AJOUT → TRANSFERRED
         */
        if ($diff > 0) {
            $this->syncIncreasedStatus($item, $diff, $auth, $order);
        }

        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);
        $newStatus = $this->resolveItemStatus($item, $diff, $newQty, $auth);

        $item->update([
            'status' => $newStatus,
        ]);
        $order = $item->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'kitchen'
        );
        $this->refreshOrderStatus($order);

        /**
         * 📊 STATS (clean)
         */
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => $newStatus
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => $newQty,
                'updated_by' => $auth->id,
                'created_by' => $auth->id,
            ]
        );

        /**
         * 🔄 STOCK
         */
        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
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

        $statuses = $item->statuses;

        /**
         * 🔻 CAS : DIMINUTION
         */
        if ($diff < 0) {
            $remainingToRemove = abs($diff);

            // 🔹 1. REJECTED
            foreach ($statuses->whereIn('status', [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ]) as $status) {

                if ($status->deleted_at) continue;

                $deduct = min($status->quantity, $remainingToRemove);
                $status->quantity -= $deduct;
                $status->updated_by = $auth->id;
                $status->save();

                $remainingToRemove -= $deduct;
                if ($remainingToRemove <= 0) break;
            }

            // 🔹 2. TRANSFERRED
            if ($remainingToRemove > 0) {
                foreach ($statuses->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value) as $status) {

                    if ($status->deleted_at) continue;

                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->updated_by = $auth->id;
                    $status->save();

                    $remainingToRemove -= $deduct;
                    if ($remainingToRemove <= 0) break;
                }
            }
        }

        /**
         * 🔺 CAS : AUGMENTATION → TRANSFERRED
         */
        $transferredStatus = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => 0,
                'quantity_accumulated' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
            ]
        );

        if ($diff > 0) {
            $transferredStatus->quantity += $diff;
        }

        $transferredStatus->quantity_exactly = $newQty;
        $transferredStatus->quantity_accumulated = $newQty;
        $transferredStatus->updated_by = $auth->id;
        $transferredStatus->save();

        /**
         * 🔥 CALCUL DU NOUVEAU STATUT GLOBAL
         */

        /**
         * 🔹 UPDATE ITEM
         */
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);
        $newStatus = $this->resolveItemStatus($item, $diff, $newQty, $auth);

        $item->update([
            'status' => $newStatus,
        ]);
        $order = $item->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'kitchen'
        );
        $this->refreshOrderStatus($order);

        /**
         * 📊 STATS
         * ⚠️ IMPORTANT : on supprime les anciennes incohérentes
         */
        StatisticsOrderStatusMenuRestaurant::where('order_menu_restaurant_item_uuid', $item->uuid)
            ->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
            ->delete();

        StatisticsOrderStatusMenuRestaurant::create([
            'order_menu_restaurant_item_uuid' => $item->uuid,
            'order_menu_restaurant_uuid' => $order->uuid,
            'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
            'quantity' => $newQty,
            'in_preparation_at' => now(),
            'make_in_preparation_by' => $auth->id,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);

        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }

        return null;
    }
    private function handleTransferred(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $item->quantity_exactly;

        if ($newQty === $oldQty) {
            return;
        }

        $diff = $newQty - $oldQty;

        DB::transaction(function () use ($item, $diff, $newQty, $oldQty, $auth, $order) {

            // 🔻 DIMINUTION
            if ($diff < 0) {

                $remainingToRemove = abs($diff);

                // 🔒 quantité DELIVERED (non touchable)
                $deliveredQty = (int) $item->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
                    ->sum('quantity');

                $maxRemovable = $oldQty - $deliveredQty;

                if ($remainingToRemove > $maxRemovable) {
                    throw new \Exception("Impossible de réduire. Une partie est déjà servie.");
                }

                // 🔹 REJECTED
                $rejectedStatuses = $item->statuses()
                    ->whereIn('status', [
                        OrderMenuRestaurantItemStatus::REJECTED->value,
                        OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                    ])
                    ->get();

                foreach ($rejectedStatuses as $status) {
                    if ($remainingToRemove <= 0) break;

                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->updated_by = $auth->id;
                    $status->save();

                    $remainingToRemove -= $deduct;
                }

                // 🔹 TRANSFERRED
                if ($remainingToRemove > 0) {
                    $transferredStatuses = $item->statuses()
                        ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                        ->get();

                    foreach ($transferredStatuses as $status) {
                        if ($remainingToRemove <= 0) break;

                        $deduct = min($status->quantity, $remainingToRemove);
                        $status->quantity -= $deduct;
                        $status->updated_by = $auth->id;
                        $status->save();

                        $remainingToRemove -= $deduct;
                    }
                }

                if ($remainingToRemove > 0) {
                    throw new \Exception("Impossible de réduire cette quantité.");
                }
            }

            // 🔺 AUGMENTATION
            if ($diff > 0) {
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

                $status->quantity += $diff;
                $status->quantity_accumulated = $newQty;
                $status->quantity_exactly = $newQty;
                $status->updated_by = $auth->id;
                $status->save();
            }
        });


        /**
         * 🔥 UPDATE ITEM
         */
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'unit_price'  => $unitPrice,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);
        $newStatus = $this->resolveItemStatus($item, $diff, $newQty, $auth);

        $item->update([
            'status' => $newStatus,
        ]);
        $order = $item->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est actuellement au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'kitchen'
        );
        $this->refreshOrderStatus($order);

        /**
         * 🔥 STATS
         */
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => $newStatus
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => abs($diff),
                'transferred_at' => now(),
                'make_transferred_by' => $auth->id,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        /**
         * 🔥 STOCK
         */
        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }
    }
    private function handleQuantityUpdate(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newTotalQty = (int) $data['quantity'];
        $oldTotalQty = (int) $item->quantity_exactly;

        if ($newTotalQty === $oldTotalQty) {
            return;
        }

        $diff = $newTotalQty - $oldTotalQty;

        DB::transaction(function () use ($item, $diff, $newTotalQty, $oldTotalQty, $auth, $order) {

            // 🔻 DIMINUTION
            if ($diff < 0) {
                $remainingToRemove = abs($diff);

                // 🔹 1. REJECTED
                $rejectedStatuses = $item->statuses()
                    ->whereIn('status', [
                        OrderMenuRestaurantItemStatus::REJECTED->value,
                        OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                    ])
                    ->get();

                foreach ($rejectedStatuses as $status) {
                    if ($remainingToRemove <= 0) break;

                    $deduct = min($status->quantity, $remainingToRemove);
                    $status->quantity -= $deduct;
                    $status->updated_by = $auth->id;
                    $status->save();

                    $remainingToRemove -= $deduct;
                }

                // 🔹 2. TRANSFERRED
                if ($remainingToRemove > 0) {
                    $transferredStatuses = $item->statuses()
                        ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                        ->get();

                    foreach ($transferredStatuses as $status) {
                        if ($remainingToRemove <= 0) break;

                        $deduct = min($status->quantity, $remainingToRemove);
                        $status->quantity -= $deduct;
                        $status->updated_by = $auth->id;
                        $status->save();

                        $remainingToRemove -= $deduct;
                    }
                }

                // ❌ protection
                if ($remainingToRemove > 0) {
                    throw new \Exception("Impossible de réduire cette quantité.");
                }
            }

            // 🔺 AUGMENTATION
            if ($diff > 0) {
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

                $status->quantity += $diff;
                $status->quantity_accumulated = $newTotalQty;
                $status->quantity_exactly = $newTotalQty;
                $status->updated_by = $auth->id;
                $status->save();
            }
        });

        /**
         * 🔥 UPDATE ITEM
         */
        $item->update([
            'quantity' => $newTotalQty,
            'quantity_exactly' => $newTotalQty,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $newTotalQty,
            'updated_by' => $auth->id,
        ]);
        $newStatus = $this->resolveItemStatus($item, $diff, $newTotalQty, $auth);

        $item->update([
            'status' => $newStatus,
        ]);
        $order = $item->order;
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $newStatus,
            "La commande {$order->code} est déjà au statut " . OrderMenuRestaurantItemStatus::safeLabel($newStatus) . ".",
            auth()->id(),
            'kitchen'
        );
        $this->refreshOrderStatus($order);

        /**
         * 🔥 STATS CLEAN
         */
        StatisticsOrderStatusMenuRestaurant::updateOrCreate(
            [
                'order_menu_restaurant_item_uuid' => $item->uuid,
                'status' => $newStatus
            ],
            [
                'order_menu_restaurant_uuid' => $order->uuid,
                'quantity' => abs($diff),
                'updated_by' => $auth->id,
                'created_by' => $auth->id,
            ]
        );

        /**
         * 🔥 STOCK
         */
        if ($diff !== 0) {
            $this->updateVirtualStock($menu, $order, $item, $diff, $auth);
        }
    }
    public function verify_to_delete_items_menu(Request $request, $order_uuid, $item_uuid)
    {
        $auth = auth()->user();
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

            $order->update([
                'updated_by' => $auth->id,
            ]);

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
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            $order = OrderMenuRestaurant::where('uuid', $order_uuid)->firstOrFail();
            $drink = OrderRestaurantDrink::where('uuid', $drink_uuid)
                ->where('order_menu_restaurant_uuid', $order->uuid)
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

            $statuses = $drink->statuses()->pluck('quantity', 'status');
            $totalQty = $statuses->sum();

            $rejectedQty = $statuses[OrderMenuRestaurantItemStatus::REJECTED->value] ?? 0;
            $transferredQty = $statuses[OrderMenuRestaurantItemStatus::TRANSFERRED->value] ?? 0;

            $allowedQty = $rejectedQty + $transferredQty;

            if ($totalQty > 0 && $allowedQty !== $totalQty) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Suppression impossible : certaines quantités sont encore actives (en attente ou validées).'
                ], 403);
            }

            $drink->virtuals()->delete();

            DrinksVirtualTemp::where('drink_restaurant_uuid', $drink_uuid)
                ->where('order_menu_restaurant_uuid', $order->uuid)
                ->delete();

            $drink->statuses()->delete();
            $drink->statistics()->delete();
            $drink->delete();

            $remainingDrinks = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)->count();
            $remainingMenus = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->count();

            if ($remainingDrinks === 0 && $remainingMenus === 0) {
                VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)->delete();
                $order->delete();

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => 'L\'élément a été supprimé et la commande a été clôturée (dernier élément).'
                ]);
            }

            $order->update([
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Boisson supprimée avec succès.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Delete item error', [
                'message' => $e->getMessage(),
                'order_uuid' => $order_uuid,
                'drink_uuid' => $drink_uuid
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression.',
                'debug' => $e->getMessage() // À retirer en production
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::index
     * @permission_desc Afficher l'interface de prises des commandes
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
            'drinks.drinkConfig.product',
            'free_client_for_restaurant',
            'notifications'
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

        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_kitchen_and_bar_orders')) {
            $roleIds = $auth->roles->pluck('id');
            $query->where(function ($q) use ($auth, $roleIds) {

                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
                if ($auth->can('view_kitchen_orders')) {
                    $q->orWhereNotNull('kitchen_user_id');
                }
                if ($auth->can('view_bar_orders')) {
                    $q->orWhereNotNull('bar_user_id');
                }

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
                'notifications',

                // ITEMS
                'items' => function ($query) {
                    $query->orderByDesc('created_at');
                },
                'items.menu',
                'items.virtuals.product',
                'items.rejector',
                'items.statuses',
                'items.defectiveByUser',
                'items.restoredByUser',
                'items.cancelForNewUpdateBy',
                'items.rejectedAfterValidationByUser',

                // DRINKS
                'drinks' => function ($query) {
                    $query->orderByDesc('created_at');
                },
                'drinks.drinkConfig.product',
                'drinks.rejector',
                'drinks.statuses',
                'drinks.defectiveByUser',
                'drinks.restoredByUser',
                'drinks.cancelForNewUpdateBy',
                'drinks.rejectedAfterValidationByUser',
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
            '*.reason' => 'required|string|max:255',
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
                $reason = $selection['reason'];

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
                    'reason' =>  $reason,
                ]);
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::REJECTED->value,
                "Commande {$order->code} rejetée en cuisine. Action requise.",
                $auth->id,
                'kitchen'
            );

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
            '*.reason' => 'required|string|max:255',
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
                $reason = $selection['reason'];

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

                StatisticsOrderStatusDrink::updateOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::REJECTED->value,
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'drink_restaurant_uuid' => $drink->drink_restaurant_uuid,
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
                    'updated_by' => $auth->id,
                    'reason' => $reason
                ]);
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::REJECTED->value,
                "Commande {$order->code} rejetée. Action requise.",
                $auth->id,
                'bar'
            );
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
                    'updated_by' => $auth->id,
                    'updated_at' => now()
                ]);
            }
            $order->update([
                'updated_by' => $auth->id,
            ]);
            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::IN_PREPARATION->value,
                "Commande {$order->code} mise en préparation. Veuillez commencer.",
                $auth->id,
                'kitchen'
            );

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

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::TRANSFERRED->value,
                "Commande {$order->code} retranférée en cuisine. Action requise.",
                $auth->id,
                'kitchen'
            );

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

            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with(['items.virtuals', 'drinks.virtuals', 'drinks.drinkConfig'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === MenuOrderStatus::FACTURATE->value) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette commande a déjà été facturée.'
                ], 403);
            }

            $warehouseRestaurant = Warehouse::where('is_used_for_restaurant', true)
                ->lockForUpdate()
                ->firstOrFail();

            $warehouseBar = Warehouse::where('is_bar_warehouse', true)
                ->lockForUpdate()
                ->firstOrFail();

            $warehouseTransformation = Warehouse::where('is_used_for_drinks_transformation', true)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                !$warehouseRestaurant ||
                !$warehouseBar ||
                !$warehouseTransformation
            ) {
                throw new \Exception(
                    "Configuration des entrepôts incomplète"
                );
            }

            foreach ($order->items as $item) {

                if (
                    $item->is_stock_deducted ||
                    $item->virtuals->isEmpty()
                ) {
                    continue;
                }

                foreach ($item->virtuals as $virtual) {

                    $qty = (float) $virtual->quantity_reserved;

                    if ($qty <= 0) {
                        continue;
                    }

                    $stock = ProductPoint::where(
                        'point_uuid',
                        $warehouseRestaurant->uuid
                    )
                        ->where(
                            'produit_uuid',
                            $virtual->product_uuid
                        )
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {

                        throw new \Exception(
                            "Stock RESTAURANT introuvable produit {$virtual->product_uuid}"
                        );
                    }

                    if ($stock->quantity < $qty) {

                        throw new \Exception(
                            "Stock RESTAURANT insuffisant produit {$virtual->product_uuid}"
                        );
                    }

                    $stock->update([
                        'quantity' => $stock->quantity - $qty,
                        'updated_by' => $auth->id
                    ]);

                    $virtual->update([
                        'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                        'updated_by' => $auth->id
                    ]);
                    MenuVirtualTemp::where(
                        'order_menu_restaurant_uuid',
                        $order->uuid
                    )
                        ->where(
                            'product_uuid',
                            $virtual->product_uuid
                        )
                        ->where('status', 'pending')
                        ->where('type', 'initial')
                        ->update([
                            'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                            'updated_by' => $auth->id
                        ]);
                }

                $item->update([
                    'is_stock_deducted' => true,
                    'updated_by' => $auth->id
                ]);
            }

            foreach ($order->drinks as $drink) {

                if (
                    $drink->is_stock_deducted ||
                    $drink->virtuals->isEmpty()
                ) {
                    continue;
                }

                $warehouseUsed = ($drink->drinkConfig && $drink->drinkConfig->is_transformable_product)
                    ? $warehouseTransformation
                    : $warehouseBar;

                $warehouseName = ($drink->drinkConfig && $drink->drinkConfig->is_transformable_product)
                    ? 'TRANSFORMATION'
                    : 'BAR';

                foreach ($drink->virtuals as $virtual) {
                    $qty = (float) $virtual->quantity_reserved;
                    if ($qty <= 0) {
                        continue;
                    }
                    $stock = ProductPoint::where('point_uuid', $warehouseUsed->uuid)
                        ->where('produit_uuid', $virtual->product_uuid)
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \Exception(
                            "Stock {$warehouseName} introuvable produit {$virtual->product_uuid}"
                        );
                    }

                    if ($stock->quantity < $qty) {
                        throw new \Exception(
                            "Stock {$warehouseName} insuffisant produit {$virtual->product_uuid}"
                        );
                    }

                    $stock->update([
                        'quantity' => $stock->quantity - $qty,
                        'updated_by' => $auth->id
                    ]);

                    $virtual->update([
                        'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                        'updated_by' => $auth->id
                    ]);

                    DrinksVirtualTemp::where('order_menu_restaurant_uuid',
                        $order->uuid
                    )
                        ->where(
                            'product_uuid',
                            $virtual->product_uuid
                        )
                        ->where('status', 'pending')
                        ->where('type', 'initial')
                        ->update([
                            'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
                            'updated_by' => $auth->id
                        ]);
                }

                $drink->update([
                    'is_stock_deducted' => true,
                    'updated_by' => $auth->id
                ]);
            }
            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::FACTURATE->value,
                "Facture générée pour la commande {$order->code}.",
                $auth->id,
                'all'
            );
            $order->update([
                'updated_by' => $auth->id,
                'status' => MenuOrderStatus::FACTURATE->value,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Facture validée avec succès (items + drinks + stocks synchronisés)'
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

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::TRANSFERRED->value,
                "Commande {$order->code} retranférée. Action requise.",
                $auth->id,
                'bar'
            );

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

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with(['drinks.statuses', 'drinks.drinkConfig'])
            ->firstOrFail();

        $now = now();

        DB::beginTransaction();

        try {

            foreach ($validated as $drinkData) {

                $drink = $order->drinks->firstWhere('uuid', $drinkData['drink_uuid']);
                if (!$drink) continue;

                $qtyRequested = (int) $drinkData['quantity_to_deliver'];

                $availableQty = $drink->statuses()
                    ->whereIn('status', [
                        OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                        OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                        OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value
                    ])
                    ->sum('quantity');

                if ($qtyRequested > $availableQty) {
                    throw new \Exception(
                        "Erreur sur {$drink->drinkConfig?->drink_name} : demandé ({$qtyRequested}) > disponible ({$availableQty})."
                    );
                }

                // 🔁 déduction stock statuts sources
                $qtyRemaining = $qtyRequested;

                $sourceStatuses = [
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value,
                    OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value

                ];

                foreach ($sourceStatuses as $statusType) {

                    if ($qtyRemaining <= 0) break;

                    $statusModel = $drink->statuses()
                        ->where('status', $statusType)
                        ->first();

                    if ($statusModel && $statusModel->quantity > 0) {

                        $take = min($qtyRemaining, $statusModel->quantity);

                        $statusModel->decrement('quantity', $take);

                        $statusModel->quantity_accumulated = $statusModel->quantity;
                        $statusModel->updated_by = $auth->id;
                        $statusModel->save();

                        $qtyRemaining -= $take;
                    }
                }

                // 🍹 PREPARATION STATUS (SANS PRODUCT)
                $prepStatus = $drink->statuses()->firstOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'quantity' => 0,
                        'quantity_accumulated' => 0,
                        'created_by' => $auth->id
                    ]
                );

                $prepStatus->update([
                    'quantity' => $prepStatus->quantity + $qtyRequested,
                    'quantity_accumulated' => $prepStatus->quantity_accumulated + $qtyRequested,
                    'updated_by' => $auth->id
                ]);

                // 📊 STATISTICS (SANS PRODUCT)
                StatisticsOrderStatusDrink::updateOrCreate(
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value
                    ],
                    [
                        'drink_restaurant_uuid' => $drink->drink_restaurant_uuid,
                        'quantity' => $prepStatus->quantity,
                        'in_preparation_at' => $now,
                        'make_in_preparation_by' => $auth->id,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]
                );



                // 🍹 UPDATE DRINK
                $drink->update([
                    'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                    'is_rejected' => false,
                    'make_in_preparation_at' => $now,
                    'updated_by' => $auth->id
                ]);
            }

            $order->update([
                'updated_by' => $auth->id,
            ]);

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::IN_PREPARATION->value,
                "Commande {$order->code} mise en préparation. Veuillez commencer.",
                $auth->id,
                'bar'
            );

            $this->refreshOrderStatus($order);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mise en préparation réussie.',
                'order' => $order->fresh(['drinks.statuses', 'drinks.drinkConfig'])
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
            $order = OrderMenuRestaurant::where('uuid', $uuid)->with(['items', 'items.menu'])->firstOrFail();

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
                $item->quantity_delivered = $newDeliveredTotal;
                $item->quantity_final_used += $qtyToDeliver;
                $item->quantity = $newRemaining;
                $item->save();

                $this->refreshItemForPartialStatusStatus($item, $auth,$order);
                $item->refresh();

                $allDeliveryLogs[] = [
                    'item_uuid' => $item->uuid,
                    'item_name' => $item->menu->name,
                    'item_type' => 'menu',
                    'quantity_ordered' => $totalOrdered,
                    'quantity_delivered_total' => $item->quantity_delivered,
                    'quantity_remaining' => $item->quantity,
                    'quantity_to_deliver' => $qtyToDeliver,
                    'status_item' => $item->status,
                ];
            }

            $itemsStatus = collect($allDeliveryLogs)->pluck('status_item');

            if ($itemsStatus->isEmpty()) {
                $orderStatus = OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;
            } else {
                $allFinished = $itemsStatus->every(
                    fn($status) => $status === OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
                );
                $orderStatus = $allFinished
                    ? OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
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

            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with([
                    'drinks',
                    'drinks.drinkConfig',
                    'drinks.statuses'
                ])
                ->firstOrFail();

            $allDeliveryLogs = [];

            foreach ($request->all() as $pItem) {

                $itemUuid = $pItem['item_uuid'];
                $qtyToDeliver = (int) $pItem['quantity_to_deliver'];

                /*
                |--------------------------------------------------------------------------
                | 📌 FIND DRINK
                |--------------------------------------------------------------------------
                */
                $item = $order->drinks->firstWhere('uuid', $itemUuid);

                if (!$item) {

                    $allDeliveryLogs[] = [
                        'item_uuid' => $itemUuid,
                        'error' => 'Boisson non trouvée dans la commande'
                    ];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | 📌 REMAINING QTY FROM IN_PREPARATION
                |--------------------------------------------------------------------------
                */
                $inPreparationQty = (int) $item->statuses
                    ->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
                    ->sum('quantity');

                if ($qtyToDeliver > $inPreparationQty) {

                    return response()->json([
                        'success' => false,
                        'message' => "Impossible de livrer {$qtyToDeliver} boissons. Quantité disponible en préparation : {$inPreparationQty}"
                    ], 422);
                }

                /*
                |--------------------------------------------------------------------------
                | 📌 UPDATE STATUS FROM PREPARATION
                |--------------------------------------------------------------------------
                */
                $this->updateDrinkStatusFromPreparation(
                    $item,
                    $qtyToDeliver,
                    $auth,
                    $order
                );

                /*
                |--------------------------------------------------------------------------
                | 📌 REFRESH ITEM
                |--------------------------------------------------------------------------
                */
                $item->refresh();

                /*
                |--------------------------------------------------------------------------
                | 📌 CALCULS
                |--------------------------------------------------------------------------
                */
                $newDeliveredTotal = (int) $item->quantity_delivered + $qtyToDeliver;

                $remaining = max(
                    0,
                    ((int) $item->quantity_exactly) - $newDeliveredTotal
                );

                $item->update([
                    'quantity_delivered' => $newDeliveredTotal,
                    'quantity_final_used' => (int) $item->quantity_final_used + $qtyToDeliver,
                    'quantity' => $remaining,
                    'updated_by' => $auth->id,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 📌 REFRESH STATUS
                |--------------------------------------------------------------------------
                */
                $this->refreshDrinkForPartialStatus($item, $auth,$order);

                $item->refresh();

                /*
                |--------------------------------------------------------------------------
                | 📌 DRINK NAME SAFE
                |--------------------------------------------------------------------------
                */
                $drinkName =
                    $item->drinkConfig?->drink_name
                    ?? 'Boisson supprimée';

                /*
                |--------------------------------------------------------------------------
                | 📌 LOGS
                |--------------------------------------------------------------------------
                */
                $allDeliveryLogs[] = [
                    'item_uuid' => $item->uuid,
                    'item_name' => $drinkName,
                    'item_type' => 'drink',
                    'quantity_ordered' => (int) $item->quantity_exactly,
                    'quantity_delivered_total' => (int) $item->quantity_delivered,
                    'quantity_remaining' => (int) $item->quantity,
                    'quantity_to_deliver' => $qtyToDeliver,
                    'status_item' => $item->status,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 📌 ORDER STATUS
            |--------------------------------------------------------------------------
            */
            $itemsStatus = collect($allDeliveryLogs)
                ->pluck('status_item');

            if ($itemsStatus->isEmpty()) {

                $orderStatus = OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;

            } else {

                $allFinished = $itemsStatus->every(
                    fn($status) =>
                        $status === OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
                );

                $orderStatus = $allFinished
                    ? OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
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

        $rejectedStatus->increment('quantity', $qtyToProcess);
        $rejectedStatus->increment('quantity_accumulated', $qtyToProcess);
        $rejectedStatus->update(['updated_by' => $auth->id, 'order_menu_restaurant_uuid' => $order->uuid]);

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
        $sourceStatus = $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)->first();

        if (!$sourceStatus || $sourceStatus->quantity <= 0) return;

        $qtyToProcess = min($qtyToReject, $sourceStatus->quantity);

        $sourceStatus->decrement('quantity', $qtyToProcess);
        if ($sourceStatus->fresh()->quantity <= 0) {
            $sourceStatus->update(['quantity_accumulated' => 0]);
        }

        $rejectedStatus = $drink->statuses()->firstOrCreate(
            [
                'status' => OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value,
                'order_restaurant_drink_uuid' => $drink->uuid
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


    private function refreshItemStatus(OrderMenuRestaurantItem $item, $auth, $order)
    {
        $item->refresh();

        $deliveredQty = (int) $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');

        $requiredQty = (int) $item->quantity_exactly;

        if ($deliveredQty === $requiredQty && $requiredQty > 0) {

            $item->status = OrderMenuRestaurantItemStatus::DELIVERED->value;

            $notificationStatus = MenuOrderStatus::DELIVERED->value;

            $message = "Commande {$order->code} servie avec succès.";

        } elseif ($deliveredQty > 0) {

            $item->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;

            $notificationStatus = MenuOrderStatus::PARTIAL_DELIVERED->value;

            $message = "Commande {$order->code} servie partiellement.";
        }

        $item->updated_by = $auth->id;
        $item->save();

        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $notificationStatus,
            $message,
            $auth->id,
            'kitchen'
        );
    }
    private function refreshItemForPartialStatusStatus(OrderMenuRestaurantItem $item, $auth,$order)
    {
        $item->refresh();
        $deliveredQty = (int) $item->statuses()->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');
        $requiredQty = (int) $item->quantity_exactly;
        if ($deliveredQty === $requiredQty && $requiredQty > 0) {
            $item->status = OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value;
            $notificationStatus = MenuOrderStatus::TOTAL_DELIVERED->value;
            $message = "Commande {$order->code} est prête.";
        } elseif ($deliveredQty > 0) {
            $item->status = OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
            $notificationStatus = MenuOrderStatus::PARTIAL_COMPLETED->value;
            $message = "Commande {$order->code} prête partiellement.";
        }
        $item->updated_by = $auth->id;
        $item->save();
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $notificationStatus,
            $message,
            $auth->id,
            'kitchen'
        );
    }
    private function refreshDrinkForPartialStatus(OrderRestaurantDrink $drink, $auth,$order)
    {
        $drink->refresh();

        $deliveredQty = (int) $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
            ->whereNull('deleted_at')->sum('quantity');

        $requiredQty = (int) $drink->quantity_exactly;
        if ($deliveredQty === $requiredQty && $requiredQty > 0) {
            $drink->status = OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value;
            $notificationStatus = MenuOrderStatus::TOTAL_DELIVERED->value;
            $message = "Commande {$order->code} est prête.";
        } elseif ($deliveredQty > 0) {
            $drink->status = OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
            $notificationStatus = MenuOrderStatus::PARTIAL_COMPLETED->value;
            $message = "Commande {$order->code} prête partiellement.";
        }
        $drink->updated_by = $auth->id;
        $drink->save();
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $notificationStatus,
            $message,
            $auth->id,
            'bar'
        );
    }

    private function refreshDrinkStatus(OrderRestaurantDrink $drink, $auth,$order)
    {
        $drink->refresh();

        $deliveredQty = (int) $drink->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
            ->whereNull('deleted_at')
            ->sum('quantity');

        $requiredQty = (int) $drink->quantity_exactly;

        if ($deliveredQty === $requiredQty && $requiredQty > 0) {
            $drink->status = OrderMenuRestaurantItemStatus::DELIVERED->value;
            $notificationStatus = MenuOrderStatus::DELIVERED->value;
            $message = "La commande {$order->code} servie avec succès.";
        } elseif ($deliveredQty > 0) {
            $drink->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
            $notificationStatus = MenuOrderStatus::PARTIAL_DELIVERED->value;
            $message = "La commande {$order->code} servie partiellement.";
        }
        $drink->updated_by = $auth->id;
        $drink->save();
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $notificationStatus,
            $message,
            $auth->id,
            'bar'
        );
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

                $this->updateItemStatusToFinalDelivery($item, $qtyToDeliver, $auth,$order);

                $this->refreshItemStatus($item, $auth, $order);
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

                $this->refreshDrinkStatus($drink, $auth,$order);
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
                $item->updated_by = $auth->id;
                $item->is_reason_of_cancel_for_new_update = true;
                $item->cancel_for_new_update_at = now();
                $item->reason_of_cancel_for_new_update = $reason;
                $item->cancel_for_new_update_by = $auth->id;
                $item->save();
            }

            $order->update([
                'status' => MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                'updated_by' => $auth->id,
            ]);
            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                "La commande {$order->code} a été rejetée pour modification.",
                $auth->id,
                'kitchen'
            );


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
                $item->updated_by = $auth->id;
                $item->rejected_after_validation_by = $auth->id;
                $item->rejected_after_validation_at = now();
                $item->reason_of_rejected_after_validation = $reason;
                $item->is_reason_of_rejected_after_validation = true;
                $item->save();
            }

            $order->update([
                'status' => MenuOrderStatus::REJECTED_AFTER_VALIDATION->value,
                'updated_by' => $auth->id,
            ]);
            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::REJECTED_AFTER_VALIDATION->value,
                "Commande {$order->code} refusée pour service.",
                $auth->id,
                'kitchen'
            );

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

            $restorationLogs = [];
            $selectedItems = collect($request->items)->keyBy('item_uuid');

            foreach ($order->drinks as $drink) {

                if (!isset($selectedItems[$drink->uuid])) continue;

                $data = $selectedItems[$drink->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

                $this->rejectDrinkFromDelivered($drink, $qtyToCancel, $auth, $order);

                $actuallyDelivered = (int) $drink->quantity_final_used;
                $restoreAmount = min($qtyToCancel, $actuallyDelivered);

                if ($restoreAmount > 0) {
                    $drink->quantity_delivered = 0;
                    $drink->quantity_final_used -= $restoreAmount;
                    $drink->quantity += $restoreAmount;
                }

                $drink->status = OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value;
                $drink->updated_by = $auth->id;
                $drink->rejected_after_validation_by = $auth->id;
                $drink->rejected_after_validation_at = now();
                $drink->reason_of_rejected_after_validation = $reason;
                $drink->is_reason_of_rejected_after_validation = true;
                $drink->save();
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::REJECTED_AFTER_VALIDATION->value,
                "Commande {$order->code} refusée pour service.Action requise!",
                $auth->id,
                'bar'
            );

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

            $logs = [];
            $selected = collect($request->items)->keyBy('item_uuid');

            foreach ($order->drinks as $drink) {

                if (!isset($selected[$drink->uuid])) continue;

                $data = $selected[$drink->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

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
                $drink->updated_by = $auth->id;
                $drink->is_reason_of_cancel_for_new_update = true;
                $drink->cancel_for_new_update_at = now();
                $drink->reason_of_cancel_for_new_update = $reason;
                $drink->cancel_for_new_update_by = $auth->id;
                $drink->save();
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                "La commande {$order->code} a été rejetée pour modification.",
                $auth->id,
                'bar'
            );

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
     * Rafraîchit le statut global d'une commande.
     * @param string $type Type d'items à considérer ('menu' ou 'drink')
     */

    private function checkIfAllOrderIsDelivered($allItems): string
    {
        // Si la commande est vide, elle n'est pas livrée
        if ($allItems->isEmpty()) {
            return MenuOrderStatus::TRANSFERRED->value;
        }

        $isAllDone = $allItems->every(function ($item) {
            $deliveredQty = (int) $item->statuses()
                ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
                ->whereNull('deleted_at')
                ->sum('quantity');

            return $deliveredQty >= (int) $item->quantity_exactly && (int) $item->quantity_exactly > 0;
        });

        return $isAllDone
            ? MenuOrderStatus::DELIVERED->value
            : MenuOrderStatus::PARTIAL_DELIVERED->value;
    }

    private function checkIfAllOrderIsReady($allItems): string
    {
        if ($allItems->isEmpty()) {
            return MenuOrderStatus::TRANSFERRED->value;
        }
        $isAllDone = $allItems->every(function ($item) {
            $deliveredQty = (int) $item->statuses()
                ->where('status', OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value)
                ->whereNull('deleted_at')
                ->sum('quantity');
            return $deliveredQty >= (int) $item->quantity_exactly && (int) $item->quantity_exactly > 0;
        });
        return $isAllDone
            ? MenuOrderStatus::TOTAL_DELIVERED->value
            : MenuOrderStatus::PARTIAL_COMPLETED->value;
    }

    private function refreshOrderStatus(OrderMenuRestaurant $order): void
    {
        $order->load(['items.statuses', 'drinks.statuses']);
        $allItems = $order->items->merge($order->drinks);

        if ($allItems->isEmpty()) {
            return;
        }

        // --- 1. LOGIQUE PRIORITAIRE : SOMME DES QUANTITÉS LIVRÉES ---
        $allDelivered = $allItems->every(function ($item) {
            $deliveredQty = (int) $item->statuses()
                ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
                ->whereNull('deleted_at')
                ->sum('quantity');
            $requiredQty = (int) $item->quantity_exactly;
            return $deliveredQty === $requiredQty && $requiredQty > 0;
        });

        $anyDelivered = $allItems->contains(function ($item) {
            return (int) $item->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)
                    ->whereNull('deleted_at')
                    ->sum('quantity') > 0;
        });

        if ($allDelivered) {
            $order->status = MenuOrderStatus::DELIVERED->value;
            $order->save();
            return;
        }

        if ($anyDelivered) {
            $order->status = MenuOrderStatus::PARTIAL_DELIVERED->value;
            $order->save();
        }

        $lastItem = $allItems->sortByDesc('updated_at')->first();

        if ($lastItem) {
            $order->status = match ($lastItem->status) {
                OrderMenuRestaurantItemStatus::REJECTED->value => MenuOrderStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value => MenuOrderStatus::NEW_REJECTED->value,
                OrderMenuRestaurantItemStatus::REJECTED_AFTER_VALIDATION->value => MenuOrderStatus::REJECTED_AFTER_VALIDATION->value,
                OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value => MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                OrderMenuRestaurantItemStatus::IN_PREPARATION->value => MenuOrderStatus::IN_PREPARATION->value,
                OrderMenuRestaurantItemStatus::TRANSFERRED->value => MenuOrderStatus::TRANSFERRED->value,
                OrderMenuRestaurantItemStatus::DEFECTIVE->value => MenuOrderStatus::DEFECTIVE->value,
                OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value => MenuOrderStatus::PARTIAL_COMPLETED->value,
                OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value => $this->checkIfAllOrderIsReady($allItems),
                OrderMenuRestaurantItemStatus::DELIVERED->value => $this->checkIfAllOrderIsDelivered($allItems),
                default => $order->status,
            };
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
                    $virtual->increment('quantity_to_remove', $qtyToDefect);
                }

                $item->update([
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                    'updated_by' => $auth->id,
                    'is_defective' => true,
                    'reason_of_defective' => $data['reason'] ?? null,
                    'defective_by' => $auth->id,
                    'defective_at' => now(),
                ]);
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::DEFECTIVE->value,
                "Commande {$order->code} marquée comme défectueuse en cuisine. Action requise",
                $auth->id,
                'kitchen'
            );

            $this->refreshOrderStatus($order->fresh());
            $order->update([
                'updated_by' => $auth->id,
            ]);

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

                $item = OrderMenuRestaurantItem::where('uuid', $data['uuid'])->with('statuses')->first();

                if (!$item) continue;

                $qtyToRestore = (int) $data['quantity_to_deliver'];
                $reason = $data['reason'] ?? null;

                $defectiveRow = $item->statuses()->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)->first();

                if (!$defectiveRow || $qtyToRestore > $defectiveRow->quantity) {
                    throw new \Exception("Quantité DEFECTIVE insuffisante pour {$item->uuid}");
                }

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
                    if ($virtual->fresh()->quantity_in_defective < 0) {
                        $virtual->update(['quantity_in_defective' => 0]);
                    }
                }

                $item->update([
                    'status' => $this->resolveItemStatusFromStatuses($item),
                    'updated_by' => $auth->id,
                    'is_restored' => true,
                    'reason_of_restoration' => $data['reason'] ?? null,
                    'restorated_by' => $auth->id,
                    'restorated_at' => now(),
                ]);
            }
            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::TRANSFERRED->value,
                "Commande {$order->code} restaurée avec succès en cuisine.",
                $auth->id,
                'kitchen'
            );

            $this->refreshOrderStatus($order);
            $order->update([
                'updated_by' => $auth->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Quantités restaurées depuis DEFECTIVE avec succès.'
            ]);
        });
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::restoreDefectiveDrinks
     * @permission_desc Restaurer les boissons d'une commande selectionnées en défectieux
     */
    public function restoreDefectiveDrinks(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|uuid|exists:order_restaurannts_drinks,uuid',
            'items.*.quantity_to_deliver' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($validated, $auth, $order) {

            foreach ($validated['items'] as $data) {
                $drink = OrderRestaurantDrink::where('uuid', $data['uuid'])
                    ->with(['statuses', 'drinkConfig'])
                    ->first();

                if (!$drink || !$drink->drinkConfig) continue;

                $qtyDrinksToRestore = (int) $data['quantity_to_deliver'];
                $drinkConfig = $drink->drinkConfig;

                $defectiveRow = $drink->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->first();

                if (!$defectiveRow || $qtyDrinksToRestore > $defectiveRow->quantity) {
                    throw new \Exception("Quantité DEFECTIVE insuffisante pour {$drinkConfig->drink_name}.");
                }

                /*
                |--------------------------------------------------------------------------
                | 📌 RÉCUPÉRATION DE L'HISTORIQUE DES PERTES
                |--------------------------------------------------------------------------
                */
                $defectHistories = OrderMenuRestaurantDefectiveDrink::where('order_restaurant_drink_uuid', $drink->uuid)
                    ->where('type', 'drink')
                    ->orderByDesc('created_at')
                    ->get();

                $remainingToRestore = $qtyDrinksToRestore;

                foreach ($defectHistories as $history) {
                    if ($remainingToRestore <= 0) break;

                    // Calcul du facteur de conversion (Volume par boisson)
                    $factor = $this->getConversionFactor($drinkConfig, $history->product_uuid);

                    // On calcule combien d'unités de boissons complètes on peut tirer de cette ligne d'historique
                    $canRestoreFromHistory = floor($history->quantity / $factor);

                    if ($canRestoreFromHistory <= 0) continue;

                    $takeDrinks = min($remainingToRestore, $canRestoreFromHistory);
                    $volumeToReturn = $takeDrinks * $factor;

                    $statusRow = $drink->statuses()->firstOrCreate(
                        ['status' => $history->status, 'order_restaurant_drink_uuid' => $drink->uuid],
                        [
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'drink_restaurant_uuid' => $drink->drink_restaurant_uuid,
                            'quantity' => 0, 'quantity_exactly' => 0, 'quantity_accumulated' => 0,
                            'created_by' => $auth->id,
                        ]
                    );
                    $statusRow->increment('quantity', $takeDrinks);
                    $statusRow->increment('quantity_exactly', $takeDrinks);
                    $defectiveRow->decrement('quantity', $takeDrinks);
                    $defectiveRow->decrement('quantity_exactly', $takeDrinks);
                    $this->syncVirtualStockAndHistory($order, $drink, $history, $takeDrinks, $auth);
                    $remainingToRestore -= $takeDrinks;
                }

                // 2. Mise à jour de la ligne principale (Statut global)
                $drink->update([
                    'status' => $this->resolveDrinkStatusFromStatuses($drink->fresh()),
                    'updated_by' => $auth->id,
                    'is_restored' => true,
                    'reason_of_restoration' => $data['reason'] ?? null,
                    'restorated_by' => $auth->id,
                    'restorated_at' => now(),
                ]);
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::TRANSFERRED->value,
                "Commande {$order->code} restaurée avec succès.",
                $auth->id,
                'bar'
            );

            $this->refreshOrderStatus($order->fresh());

            return response()->json(['status' => 'success', 'message' => 'Restauration terminée.']);
        });
    }

    /**
     * Gère le mouvement dans VirtualOrderMenuRestaurant et le nettoyage de DefectiveDrink (Historique)
     */
    private function syncVirtualStockAndHistory($order, $drink, $history, $qtyDrinksRestored, $auth)
    {
        $drinkConfig = $drink->drinkConfig;

        if ($drinkConfig->is_transformable_product) {

            $composition = DrinkComposition::where('drinks_restaurant_uuid', $drinkConfig->uuid)->first();
            if (!$composition) {
                return;
            }
            $items = DrinkCompositionItem::where('drink_composition_uuid', $composition->uuid)->get();
            foreach ($items as $item) {
                $volumeToReturn = $qtyDrinksRestored * $item->quantity_used;
                $virtual = VirtualOrderMenuRestaurant::where([
                    'item_uuid' => $drink->uuid,
                    'orders_menu_restaurant_uuid' => $order->uuid,
                    'product_uuid' => $item->product_uuid,
                    'status' => 'pending'
                ])->first();
                if ($virtual) {
                    $newQty = max(0, $virtual->quantity_in_defective - $volumeToReturn);
                    $virtual->update([
                        'quantity_in_defective' => $newQty
                    ]);
                }

                $defectiveHistory = OrderMenuRestaurantDefectiveDrink::where([
                    'order_restaurant_drink_uuid' => $drink->uuid,
                    'product_uuid' => $item->product_uuid,
                    'type' => 'drink'])->latest()->first();

                if ($defectiveHistory) {

                    if ($defectiveHistory->quantity <= $volumeToReturn) {
                        $defectiveHistory->delete();
                    } else {
                        $defectiveHistory->decrement(
                            'quantity',
                            $volumeToReturn
                        );
                    }
                }
            }

            return;
        }

        $virtual = VirtualOrderMenuRestaurant::where([
            'item_uuid' => $drink->uuid,
            'orders_menu_restaurant_uuid' => $order->uuid,
            'product_uuid' => $history->product_uuid,
            'status' => 'pending'
        ])->first();

        if ($virtual) {

            $newQty = max(
                0,
                $virtual->quantity_in_defective - $qtyDrinksRestored
            );

            $virtual->update([
                'quantity_in_defective' => $newQty
            ]);
        }
        if ($history->quantity <= $qtyDrinksRestored) {
            $history->delete();
        } else {
            $history->decrement('quantity', $qtyDrinksRestored);
        }
    }

    /**
     * Détermine le ratio (Quantité utilisée par unité de boisson)
     */
    private function getConversionFactor($drinkConfig, $productUuid)
    {
        if ($drinkConfig->is_transformable_product) {
            $composition = DrinkComposition::where('drinks_restaurant_uuid', $drinkConfig->uuid)->first();
            if ($composition) {
                $item = DrinkCompositionItem::where('drink_composition_uuid', $composition->uuid)
                    ->where('product_uuid', $productUuid)
                    ->first();
                return $item ? (float) $item->quantity_used : 1.0;
            }
        }
        return 1.0;
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

        return DB::transaction(function () use ($validated, $auth, $order, $priorityStatuses
        ) {

            foreach ($validated['items'] as $data) {
                $drink = OrderRestaurantDrink::where('uuid', $data['uuid'])->with(['statuses', 'drinkConfig'])->first();

                if (!$drink || !$drink->drinkConfig) {
                    continue;
                }
                $drinkConfig = $drink->drinkConfig;
                $qtyDrinksToDefect = (int) $data['quantity_to_deliver'];

                /*
                |--------------------------------------------------------------------------
                | 📌 CHECK AVAILABLE QTY
                |--------------------------------------------------------------------------
                */
                $availableQty = $drink->statuses()
                    ->whereIn('status', $priorityStatuses)
                    ->sum('quantity');

                if ($qtyDrinksToDefect > $availableQty) {
                    throw new \Exception(
                        "Quantité insuffisante pour {$drink->uuid}"
                    );
                }

                LastStatusDrinksMenusRestaurant::updateOrCreate(
                    [
                        'order_restaurant_drink_uuid' => $drink->uuid,
                        'type' => 'drink',
                    ],
                    [
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'drink_restaurant_uuid' => $drink->drink_restaurant_uuid,
                        'last_status' => $drink->status,
                        'updated_by' => $auth->id,
                    ]
                );

                $remainingDrinksToProcess = $qtyDrinksToDefect;

                foreach ($priorityStatuses as $statusName) {

                    if ($remainingDrinksToProcess <= 0) {
                        break;
                    }

                    $statusRow = $drink->statuses()->where('status', $statusName)->first();

                    if (!$statusRow || $statusRow->quantity <= 0) {
                        continue;
                    }

                    $takeDrinks = min($remainingDrinksToProcess, $statusRow->quantity);

                    $statusRow->quantity -= $takeDrinks;
                    $statusRow->updated_by = $auth->id;
                    $statusRow->save();

                    $defective = $drink->statuses()->firstOrCreate(
                        [
                            'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                            'order_restaurant_drink_uuid' => $drink->uuid,
                        ],
                        [
                            'order_menu_restaurant_uuid' => $order->uuid,
                            'drink_restaurant_uuid' => $drink->drink_restaurant_uuid,
                            'quantity' => 0,
                            'quantity_exactly' => 0,
                            'quantity_accumulated' => 0,
                            'created_by' => $auth->id,
                            'updated_by' => $auth->id,
                        ]
                    );

                    $defective->quantity += $takeDrinks;

                    $defective->quantity_exactly = $defective->quantity;

                    $defective->quantity_accumulated += $takeDrinks;

                    $defective->updated_by = $auth->id;

                    $defective->save();

                    $this->updateVirtualStockForDefective(
                        $order,
                        $drink,
                        $drinkConfig,
                        $takeDrinks,
                        $statusName,
                        $data['reason'] ?? null,
                        $auth
                    );

                    $remainingDrinksToProcess -= $takeDrinks;
                }

                $drink->update([
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value,
                    'updated_by' => $auth->id,
                    'is_defective' => true,
                    'reason_of_defective' => $data['reason'] ?? null,
                    'defective_by' => $auth->id,
                    'defective_at' => now(),
                ]);
            }

            \App\Models\OrderNotification::createOrUpdateNotification(
                $order->uuid,
                MenuOrderStatus::DEFECTIVE->value,
                "Commande {$order->code} marquée comme défectueuse. Action requise",
                $auth->id,
                'bar'
            );

            $this->refreshOrderStatus($order->fresh());

            return response()->json([
                'status' => 'success',
                'message' => 'Stock défectueux mis à jour.',
            ]);
        });
    }

    /**
    |--------------------------------------------------------------------------
    | 📌 UPDATE VIRTUAL + HISTORY
    |--------------------------------------------------------------------------
     */
    private function updateVirtualStockForDefective($order, $drink, $drinkConfig, $qtyDrinks, $fromStatus, $reason, $auth) {

        $ingredients = [];

        /*
        |--------------------------------------------------------------------------
        | 📌 COMPOSED DRINK
        |--------------------------------------------------------------------------
        */
        if ($drinkConfig->is_transformable_product) {

            $composition = DrinkComposition::where(
                'drinks_restaurant_uuid',
                $drinkConfig->uuid
            )
                ->with('items')
                ->first();

            if (!$composition || $composition->items->isEmpty()) {
                return;
            }

            foreach ($composition->items as $item) {

                if (!$item->product_uuid) {
                    continue;
                }

                $ingredients[] = [
                    'product_uuid' => $item->product_uuid,
                    'qty_to_remove' => $qtyDrinks * (float) $item->quantity_used,
                ];
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | 📌 SIMPLE DRINK
            |--------------------------------------------------------------------------
            */
            if (!$drinkConfig->product_uuid) {
                return;
            }

            $ingredients[] = [
                'product_uuid' => $drinkConfig->product_uuid,
                'qty_to_remove' => (float) $qtyDrinks,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 📌 SAVE HISTORY + UPDATE VIRTUAL
        |--------------------------------------------------------------------------
        */
        foreach ($ingredients as $ing) {

            /*
            |--------------------------------------------------------------------------
            | 📌 HISTORY
            |--------------------------------------------------------------------------
            */
            OrderMenuRestaurantDefectiveDrink::create([
                'order_restaurant_drink_uuid' => $drink->uuid,
                'order_menu_restaurant_uuid' => $order->uuid,
                'product_uuid' => $ing['product_uuid'],
                'status' => $fromStatus,
                'quantity' => $ing['qty_to_remove'],
                'reason' => $reason,
                'type' => 'drink',
                'created_by' => $auth->id,
            ]);

            $virtual = VirtualOrderMenuRestaurant::firstOrCreate(
                [
                    'item_uuid' => $drink->uuid,
                    'orders_menu_restaurant_uuid' => $order->uuid,
                    'product_uuid' => $ing['product_uuid'],
                    'item_type' => 'drink',
                    'status' => 'pending',
                ],
                [
                    'quantity_in_defective' => 0,
                    'quantity_reserved' => 0,
                    'quantity_exactly' => 0,
                    'quantity_to_remove' => 0,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]
            );

            $virtual->quantity_in_defective += $ing['qty_to_remove'];
            $virtual->quantity_to_remove += $ing['qty_to_remove'];

            $virtual->updated_by = $auth->id;

            $virtual->save();
        }
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


            foreach ($request->items as $data) {

                $item = OrderMenuRestaurantItem::where('uuid', $data['uuid'])->with(['statuses', 'virtuals'])->lockForUpdate()->first();

                if (!$item) continue;

                $defective = $item->statuses->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)->first();

                if (!$defective || $defective->quantity <= 0) continue;

                $qty = (int) $defective->quantity;

                foreach ($item->virtuals->where('item_type', 'menu') as $v) {

                    $toDeduct = $v->quantity_in_defective;
                    $toDeductExactly = $v->quantity_to_remove;

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
                    $v->decrement('quantity', $toDeductExactly);
                    $v->decrement('quantity_to_remove', $toDeductExactly);

                }

                MenuVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
                    ->where(function ($query) {
                        $query->whereIn('type', ['initial', 'editing','not_used'])
                            ->orWhereNull('reservation_uuid');
                    })
                    ->forceDelete();

                $virtualItems = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
                    ->where('status', 'pending')
                    ->where('item_type', 'menu')
                    ->get();

                $ItemMenu = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->get();

                foreach ($virtualItems as $virtualItem) {
                    $menuItem = $ItemMenu->firstWhere('uuid', $virtualItem->item_uuid);

                    if (!$menuItem) {
                        continue;
                    }

                    MenuVirtualTemp::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'reservation_uuid' => $order->reservation_uuid,
                        'menus_restaurant_uuid' => $menuItem->menus_restaurant_uuid,
                        'product_uuid' => $virtualItem->product_uuid,
                        'type' => 'initial',
                        'quantity' => $virtualItem->quantity,
                        'quantity_used' => $virtualItem->quantity_reserved,
                        'status' => 'pending',
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'last_activity_at' => now(),
                    ]);
                }

                $item->update([
                    'quantity_exactly' => max(0, $item->quantity_exactly - $qty),
                    'quantity' => max(0, $item->quantity - $qty),
                    'updated_by' => $auth->id,
                ]);
                $this->refreshItemStatusAfterDelete($item, $auth,$order);

                $item->statuses()
                    ->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                    ->update([
                        'quantity_exactly' => DB::raw("GREATEST(quantity_exactly - {$qty}, 0)"),
                        'quantity_accumulated' => DB::raw("GREATEST(quantity_accumulated - {$qty}, 0)"),
                        'updated_by' => $auth->id,
                    ]);

                $defective->delete();

                StatisticsOrderStatusMenuRestaurant::where(['order_menu_restaurant_item_uuid' => $item->uuid, 'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value])->delete();

                $hasRemaining = $item->statuses()
                    ->where('status', '!=', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->exists();

                if (!$hasRemaining) {
                    $item->statuses()->delete();
                    $item->delete();
                }
            }

            $this->refreshOrderStatus($order);

            $order->update([
                'updated_by' => $auth->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Défectueux supprimés + stock restauré correctement.'
            ]);
        });
    }

    private function refreshItemStatusAfterDelete(OrderMenuRestaurantItem $item, $auth,$order)
    {
        $item->refresh();
        $deliveredQty = (int) $item->statuses()->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)->whereNull('deleted_at')
            ->sum('quantity');
        $requiredQty = (int) $item->quantity_exactly;
        $currentStatus = $this->resolveItemStatusFromStatuses($item);

        if ($requiredQty > 0 && $deliveredQty === $requiredQty) {
            $status = OrderMenuRestaurantItemStatus::DELIVERED->value;
            $notificationStatus = MenuOrderStatus::TOTAL_DELIVERED->value;
            $message = "Commande {$order->code} est servie.";
        } else {
            $status = $currentStatus;
            $notificationStatus = $currentStatus;
            $message = "Commande {$order->code} au statut " . MenuOrderStatus::safeLabel($currentStatus);
        }
        $item->update([
            'status' => $status,
            'updated_by' => $auth->id
        ]);
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $notificationStatus,
            $message,
            $auth->id,
            'kitchen'
        );
    }

    private function refreshDrinksStatusAfterDelete(OrderRestaurantDrink $drink, $auth,$order)
    {
        $drink->refresh();

        $deliveredQty = (int) $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::DELIVERED->value)->whereNull('deleted_at')
            ->sum('quantity');
        $requiredQty = (int) $drink->quantity_exactly;
        $currentStatus = $this->resolveDrinkStatusFromStatuses($drink);
        if ($requiredQty > 0 && $deliveredQty === $requiredQty) {
            $status = OrderMenuRestaurantItemStatus::DELIVERED->value;
            $notificationStatus = MenuOrderStatus::TOTAL_DELIVERED->value;
            $message = "Commande {$order->code} est servie.";
        } else {
            $status = $currentStatus;
            $notificationStatus = $currentStatus;
            $message = "Commande {$order->code} au statut " . MenuOrderStatus::safeLabel($currentStatus);
        }
        $drink->update([
            'status' => $status,
            'updated_by' => $auth->id
        ]);
        \App\Models\OrderNotification::createOrUpdateNotification(
            $order->uuid,
            $notificationStatus,
            $message,
            $auth->id,
            'bar'
        );
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::deleteDefectiveDrinks
     * @permission_desc Supprimer les boissons d'une commande marqués défectieux
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

            foreach ($request->items as $data) {

                $drink = OrderRestaurantDrink::where('uuid', $data['uuid'])
                    ->with(['statuses', 'virtuals', 'drinkConfig'])->first();

                if (!$drink) {
                    continue;
                }

                $defective = $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::DEFECTIVE->value)->first();

                if (!$defective || $defective->quantity <= 0) {
                    continue;
                }

                $qty = (int) $defective->quantity;

                $drinkConfig = $drink->drinkConfig;

                if ($drinkConfig && $drinkConfig->is_transformable_product) {
                    $warehouse = Warehouse::where('is_used_for_drinks_transformation', true)->lockForUpdate()->firstOrFail();
                } else {
                    $warehouse = Warehouse::where('is_bar_warehouse', true)->lockForUpdate()->firstOrFail();
                }

                foreach ($drink->virtuals->where('item_type', 'drink') as $v) {
                    $toDeduct = (float) $v->quantity_in_defective;
                    $toDeductExactly = (float) $v->quantity_to_remove;
                    if ($toDeduct <= 0) {
                        continue;
                    }
                    $productPoint = ProductPoint::where('produit_uuid', $v->product_uuid)
                        ->where('point_uuid', $warehouse->uuid)->lockForUpdate()->first();
                    if (!$productPoint) {
                        throw new \Exception(
                            "Stock introuvable pour produit {$v->product_uuid}"
                        );
                    }

                    $productPoint->update(['quantity' => max(0, $productPoint->quantity - $toDeduct),
                        'updated_by' => $auth->id,
                    ]);

                    $v->update([
                        'quantity_in_defective' => max(0, $v->quantity_in_defective - $toDeduct),
                        'quantity_reserved' => max(0, $v->quantity_reserved - $toDeduct),
                        'quantity' => max(0, $v->quantity - $toDeductExactly),
                        'quantity_to_remove' => max(0, $v->quantity_to_remove - $toDeductExactly),
                        'updated_by' => $auth->id,
                    ]);

                }

                DrinksVirtualTemp::where('order_menu_restaurant_uuid', $order->uuid)
                    ->where(function ($query) {
                        $query->whereIn('type', ['initial', 'editing'])
                            ->orWhereNull('reservation_uuid');
                    })
                    ->forceDelete();

                $virtualItemsDrinks = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
                    ->where('status', 'pending')
                    ->where('item_type', 'drink')
                    ->get();

                $itemDrinks = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)
                    ->get();

                foreach ($virtualItemsDrinks as $virtualDrink) {

                    $realDrink = $itemDrinks->firstWhere('uuid', $virtualDrink->item_uuid);

                    if (!$realDrink) {
                        continue;
                    }

                    DrinksVirtualTemp::create([
                        'order_menu_restaurant_uuid' => $order->uuid,
                        'reservation_uuid' => $order->reservation_uuid,
                        'drink_restaurant_uuid' => $realDrink->drink_restaurant_uuid,
                        'product_uuid' => $virtualDrink->product_uuid,
                        'type' => 'initial',
                        'status' => 'pending',
                        'quantity' => $virtualDrink->quantity,
                        'quantity_used' => $virtualDrink->quantity_reserved,

                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),

                        'last_activity_at' => now(),
                    ]);
                }

                $drink->update([
                    'quantity_exactly' => max(
                        0,
                        $drink->quantity_exactly - $qty
                    ),

                    'quantity' => max(
                        0,
                        $drink->quantity - $qty
                    ),

                    'updated_by' => $auth->id,
                ]);

                $this->refreshDrinksStatusAfterDelete($drink, $auth,$order);

                $drink->statuses()->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)
                    ->update([

                        'quantity_exactly' => DB::raw(
                            "GREATEST(quantity_exactly - {$qty}, 0)"
                        ),

                        'quantity_accumulated' => DB::raw(
                            "GREATEST(quantity_accumulated - {$qty}, 0)"
                        ),

                        'updated_by' => $auth->id,
                    ]);

                $defective->delete();

                StatisticsOrderStatusDrink::where(['order_restaurant_drink_uuid' => $drink->uuid,
                    'status' => OrderMenuRestaurantItemStatus::DEFECTIVE->value])->delete();


                OrderMenuRestaurantDefectiveDrink::where('order_restaurant_drink_uuid', $drink->uuid)->delete();

                $hasRemaining = $drink->statuses()->where('status', '!=', OrderMenuRestaurantItemStatus::DEFECTIVE->value)
                    ->exists();

                if (!$hasRemaining) {
                    $drink->statuses()->delete();
                    $drink->delete();
                }
            }

            $this->refreshOrderStatus($order->fresh());

            $order->update([
                'updated_by' => $auth->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Les boissons défectueuses ont été supprimées avec succès.'
            ]);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::destroy
     * @permission_desc Supprimer définitivement une commande
     */
    public function destroy(Request $request, string $uuid)
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

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        if ($order->is_in_editing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cette commande est actuellement en cours de modification.',
            ], 409);
        }

        DB::beginTransaction();

        try {

            $this->deleteOrderRelations($order->uuid);

            $order->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Commande supprimée avec succès.',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(), // 🔥 erreur exacte
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }
    }

    private function deleteOrderRelations(string $orderUuid): void
    {
        MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        DrinksVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        OrderRestaurantDrink::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        OrderMenuItemStatusForDrink::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        OrderMenuItemStatus::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        LastStatusDrinksMenusRestaurant::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        LastStatusItemsMenusRestaurant::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        OrderMenuRestaurantDefectiveDrink::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        OrderMenuRestaurantDefectiveItem::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        \App\Models\OrderNotification::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        StatisticsOrderStatusMenuRestaurant::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        StatisticsOrderStatusDrink::where('order_menu_restaurant_uuid', $orderUuid)->delete();
        VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)->delete();
    }



}

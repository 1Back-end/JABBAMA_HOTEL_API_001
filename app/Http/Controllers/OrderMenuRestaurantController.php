<?php

namespace App\Http\Controllers;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\TypeClientsForPaiment;
use App\Enums\VirtualOrderMenuRestaurantStatus;
use App\Models\InvoiceForMenuOrder;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\MenuRestaurant;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\Role;
use App\Models\User;
use App\Models\VirtualOrderMenuRestaurant;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
/**
 * @permission_category Gestion des commandes du restaurant
 * @permission_module Gestion du restaurant
 */
class OrderMenuRestaurantController extends Controller
{

    private function verifyBarStock(array $drinks): array
    {
        $warehouseUuid = Warehouse::where('is_bar_warehouse', true)->firstOrFail()->uuid;
        $stockErrors = [];

        foreach ($drinks as $drink) {
            $product = Product::find($drink['product_uuid']);
            $requiredQuantity = $drink['quantity'];

            $pointStock = (float) ProductPoint::where('produit_uuid', $drink['product_uuid'])
                ->where('point_uuid', $warehouseUuid)
                ->value('quantity') ?? 0;

            if ($requiredQuantity > $pointStock) {
                $stockErrors[] = [
                    'product_uuid' => $drink['product_uuid'],
                    'product_name' => $product?->name ?? 'Inconnu',
                    'quantity_required' => $requiredQuantity,
                    'quantity_in_stock' => $pointStock,
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

            // 2. Déterminer l'entrepôt
            $warehouseUuid = $validated['warehouse_uuid'] ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            // 3. Vérification des stocks (Menus)
            $menuStockErrors = $this->verifyMenuStock($validated['menus'], $warehouseUuid);
            if (!empty($menuStockErrors)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Stock insuffisant pour certains menus.',
                    'details' => $menuStockErrors,
                ], 422);
            }

            // 4. Vérification des stocks (Boissons)
            if (!empty($validated['drinks'])) {
                $barStockErrors = $this->verifyBarStock($validated['drinks']);
                if (!empty($barStockErrors)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Stock de boissons insuffisant.',
                        'details' => $barStockErrors,
                    ], 422);
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
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_last_items' => true
                ]);

                // Réserve virtuelle basée sur la composition du menu
                $compositions = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();
                foreach ($compositions as $comp) {
                    VirtualOrderMenuRestaurant::create([
                        'orders_menu_restaurant_uuid' => $order->uuid,
                        'item_uuid' => $orderItem->uuid,
                        'item_type' => 'menu', // <--- AJOUT DU TYPE MENU
                        'product_uuid' => $comp->product_uuid,
                        'quantity_reserved' => $mInput['quantity'] * $comp->quantity_used,
                        'quantity_exactly' => $mInput['quantity'] * $comp->quantity_used,
                        'quantity_delivered_exactly' => $mInput['quantity'] * $comp->quantity_used,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_last_items' => true
                    ]);
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
                        'status' => \App\Enums\OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_last_items' => true
                    ]);

                    // AJOUT : Enregistrement de la boisson dans la table virtuelle
                    VirtualOrderMenuRestaurant::create([
                        'orders_menu_restaurant_uuid' => $order->uuid,
                        'item_uuid' => $drinkOrder->uuid,
                        'item_type' => 'drink',
                        'product_uuid' => $drinkInput['product_uuid'],
                        'quantity_reserved' => $drinkInput['quantity'],
                        'quantity_exactly' => $drinkInput['quantity'],
                        'quantity_delivered_exactly' => $drinkInput['quantity'],
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_last_items' => true
                    ]);
                }
            }

            // 8. Transfert automatique au Cuisinier
            $cuisinierRole = Role::where('name', 'CUISINIER')->first();
            if ($cuisinierRole && $recipient = $cuisinierRole->users()->first()) {
                $order->update([
                    'status' => MenuOrderStatus::TRANSFERED->value,
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
                'message' => 'Erreur technique lors de la commande.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::update
     * @permission_desc Modifier les commandes
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            // 🔹 1. Récupérer la commande
            $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

            // 🔹 2. Validation
            $validated = $request->validate([
                'type_clients_for_payment' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
                'restaurant_table_uuid' => ['nullable','uuid','required_if:consumption_type,' . ConsumptionType::DINE_IN->value, 'exists:restaurant_tables,uuid'],
                'order_menu_restaurant_date' => ['required', 'date_format:Y-m-d H:i:s'],
                'consumption_type' => ['required', 'string', new Enum(ConsumptionType::class)],
                'partners_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value],
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

            // 🔹 3. Déterminer l'entrepôt
            $warehouseUuid = $validated['warehouse_uuid']
                ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            // 🔹 4. Vérification stocks
            $menuErrors = $this->verifyMenuStock($validated['menus'], $warehouseUuid);
            if ($menuErrors) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Stock insuffisant pour certains menus.',
                    'details' => $menuErrors,
                ], 422);
            }

            if (!empty($validated['drinks'])) {
                $drinkErrors = $this->verifyBarStock($validated['drinks']);
                if ($drinkErrors) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Stock insuffisant pour certaines boissons.',
                        'details' => $drinkErrors,
                    ], 422);
                }
            }

            // 🔹 5. Mise à jour commande principale
            $order->update([
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
                'updated_by' => $auth->id,
            ]);

            // 🔹 6. Nettoyage ancien contenu
            $order->items()->delete();
            $order->drinks()->delete();
            VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)->delete();

            // 🔹 7. Réinsertion des menus
            foreach ($validated['menus'] as $m) {
                $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);
                $isFree = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;

                $unitPrice = $m['unit_price'] ?? $menu->price ?? 0;
                $totalPrice = $isFree ? 0 : $unitPrice * $m['quantity'];

                $item = OrderMenuRestaurantItem::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'menus_restaurant_uuid' => $menu->uuid,
                    'quantity' => $m['quantity'],
                    'quantity_exactly' => $m['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'is_free' => $isFree,
                    'status' => OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_last_items' => true
                ]);

                foreach (MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get() as $comp) {
                    VirtualOrderMenuRestaurant::create([
                        'orders_menu_restaurant_uuid' => $order->uuid,
                        'item_uuid' => $item->uuid,
                        'product_uuid' => $comp->product_uuid,
                        'quantity_reserved' => $m['quantity'] * $comp->quantity_used,
                        'quantity_exactly' => $m['quantity'] * $comp->quantity_used,
                        'quantity_delivered_exactly' => $m['quantity'] * $comp->quantity_used,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_last_items' => true
                    ]);
                }
            }

            // 🔹 8. Réinsertion boissons
            foreach ($validated['drinks'] ?? [] as $d) {
                $drink = OrderRestaurantDrink::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'product_uuid' => $d['product_uuid'],
                    'quantity' => $d['quantity'],
                    'quantity_exactly' => $d['quantity'],
                    'unit_price' => $d['unit_price'] ?? 0,
                    'total_price' => ($d['unit_price'] ?? 0) * $d['quantity'],
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_last_items' => true
                ]);

                VirtualOrderMenuRestaurant::create([
                    'orders_menu_restaurant_uuid' => $order->uuid,
                    'item_uuid' => $drink->uuid,
                    'product_uuid' => $d['product_uuid'],
                    'quantity_reserved' => $d['quantity'],
                    'quantity_exactly' => $d['quantity'],
                    'quantity_delivered_exactly' => $d['quantity'],
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_last_items' => true
                ]);
            }

            // 8. Transfert automatique au Cuisinier
            $cuisinierRole = Role::where('name', 'CUISINIER')->first();
            if ($cuisinierRole && $recipient = $cuisinierRole->users()->first()) {
                $order->update([
                    'status' => MenuOrderStatus::TRANSFERED->value,
                    'received_by' => $recipient->id,
                    'transfered_at' => now(),
                    'transfered_by' => $auth->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Commande mise à jour avec succès',
                'order_uuid' => $order->uuid
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Erreur update order', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour',
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::addItemsToOrder
     * @permission_desc Ajuster une commandes
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
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
                'menus.*.unit_price' => ['nullable', 'numeric', 'min:0'],

                'drinks' => ['nullable', 'array'],
                'drinks.*.product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
                'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
                'drinks.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            $warehouseUuid = $validated['warehouse_uuid']
                ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            /*
            |--------------------------------------------------------------------------
            | Vérification stock
            |--------------------------------------------------------------------------
            */

            $menuErrors = $this->verifyMenuStock($validated['menus'] ?? [], $warehouseUuid);
            if ($menuErrors) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Stock insuffisant pour certains menus.',
                    'details' => $menuErrors,
                ], 422);
            }

            if (!empty($validated['drinks'])) {
                $drinkErrors = $this->verifyBarStock($validated['drinks']);
                if ($drinkErrors) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Stock insuffisant pour certaines boissons.',
                        'details' => $drinkErrors,
                    ], 422);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | MENUS
            |--------------------------------------------------------------------------
            */

            foreach ($validated['menus'] ?? [] as $m) {

                $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);
                $unitPrice = $m['unit_price'] ?? $menu->price ?? 0;

                $isLastItem = $m['is_last_items'] ?? false;

                if ($isLastItem) {
                    continue;
                }

                // Vérifier si ce menu existe déjà dans la commande
                $existingItem = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)
                    ->where('menus_restaurant_uuid', $menu->uuid)
                    ->first();

                if ($existingItem) {
                    continue;
                }

                $item = OrderMenuRestaurantItem::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'menus_restaurant_uuid' => $menu->uuid,
                    'quantity' => $m['quantity'],
                    'quantity_exactly' => $m['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $m['quantity'],
                    'status' => OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_new_items' => true,
                    'is_last_items' => false
                ]);

                $components = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();

                foreach ($components as $comp) {

                    VirtualOrderMenuRestaurant::create([
                        'orders_menu_restaurant_uuid' => $order->uuid,
                        'item_uuid' => $item->uuid,
                        'product_uuid' => $comp->product_uuid,
                        'quantity_reserved' => $m['quantity'] * $comp->quantity_used,
                        'quantity_exactly' => $m['quantity'] * $comp->quantity_used,
                        'quantity_delivered_exactly' => $m['quantity'] * $comp->quantity_used,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                        'is_new_items' => true,
                        'is_last_items' => false
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | DRINKS
            |--------------------------------------------------------------------------
            */

            foreach ($validated['drinks'] ?? [] as $d) {

                $isLastItem = $d['is_last_items'] ?? false;

                if ($isLastItem) {
                    continue;
                }

                // vérifier si la boisson existe déjà
                $existingDrink = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $order->uuid)
                    ->where('product_uuid', $d['product_uuid'])
                    ->first();

                if ($existingDrink) {
                    continue;
                }

                $unitPrice = $d['unit_price'] ?? 0;

                $drink = OrderRestaurantDrink::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'product_uuid' => $d['product_uuid'],
                    'quantity' => $d['quantity'],
                    'quantity_exactly' => $d['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $d['quantity'],
                    'status' => OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_new_items' => true,
                    'is_last_items' => false
                ]);

                VirtualOrderMenuRestaurant::create([
                    'orders_menu_restaurant_uuid' => $order->uuid,
                    'item_uuid' => $drink->uuid,
                    'product_uuid' => $d['product_uuid'],
                    'quantity_reserved' => $d['quantity'],
                    'quantity_exactly' => $d['quantity'],
                    'quantity_delivered_exactly' => $d['quantity'],
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_new_items' => true,
                    'is_last_items' => false
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Nouveaux éléments ajoutés à la commande'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l’ajout des éléments'
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




    public function checkStockOnly(Request $request)
    {
        $auth = auth()->user();
        \Log::info('Check Stock Request:', $request->all());

        try {
            // 🔹 Validation minimale
            $validated = $request->validate([
                'menus' => ['required', 'array', 'min:1'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            $warehouseUuid = Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            $results = [];

            // 🔹 Construire la composition des menus
            foreach ($validated['menus'] as $menuInput) {
                $menu = MenuRestaurant::find($menuInput['menus_restaurant_uuid']);

                $menuItems = MenuOrderItem::with('product')
                    ->where('menus_restaurant_uuid', $menuInput['menus_restaurant_uuid'])
                    ->get();

                $menuQuantity = $menuInput['quantity'] ?? 0;
                $composition = [];

                foreach ($menuItems as $item) {
                    $productName = $item->product->name ?? 'Inconnu';
                    $productUuid = $item->product_uuid ?? null;
                    $quantityPerMenu = $item->quantity_used ?? 0;
                    $totalQuantityUsed = $menuQuantity * $quantityPerMenu;

                    $composition[] = [
                        'product_uuid' => $productUuid,
                        'product_name' => $productName,
                        'quantity_per_menu' => $quantityPerMenu,
                        'menu_quantity' => $menuQuantity,
                        'total_quantity_used' => $totalQuantityUsed,
                    ];
                }

                $results[] = [
                    'menu' => [
                        'uuid' => $menuInput['menus_restaurant_uuid'] ?? null,
                        'name' => $menu->name ?? 'Menu inconnu',
                        'quantity_ordered' => $menuQuantity,
                    ],
                    'composition' => $composition,
                ];
            }

            // 🔹 Vérifier les stocks
            $stockErrors = [];
            foreach ($results as $menuResult) {
                foreach ($menuResult['composition'] as $product) {
                    $pointStock = (float) ProductPoint::where('produit_uuid', $product['product_uuid'])
                        ->where('point_uuid', $warehouseUuid)
                        ->value('quantity') ?? 0;

                    if ($product['total_quantity_used'] > $pointStock) {
                        $stockErrors[] = [
                            'menu_uuid' => $menuResult['menu']['uuid'],
                            'menu_name' => $menuResult['menu']['name'],
                            'product_uuid' => $product['product_uuid'],
                            'product_name' => $product['product_name'],
                            'quantity_required' => $product['total_quantity_used'],
                            'quantity_in_stock' => $pointStock,
                        ];
                    }
                }
            }

            // 🔹 Retourner le résultat
            if (!empty($stockErrors)) {
                $messages = [];

                foreach ($stockErrors as $err) {
                    // Message détaillé pour chaque produit manquant
                    $messages[] = "Menu « {$err['menu_name']} » : article « {$err['product_name']} » insuffisant (en stock : {$err['quantity_in_stock']})";
                }

                return response()->json([
                    'status' => 'error',
                    'message' => implode(' | ', $messages),
                    'details' => $stockErrors, // tu gardes le tableau complet pour le frontend
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Stock suffisant pour tous les menus',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation exception', $e->errors());
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Exception in check stock', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la vérification du stock.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    public function checkBarStockOnly(Request $request)
    {
        $auth = auth()->user();
        \Log::info('Check Bar Stock Request:', $request->all());

        try {
            // 🔹 Validation minimale pour les boissons
            $validated = $request->validate([
                'drinks' => ['required', 'array', 'min:1'],
                'drinks.*.product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
                'drinks.*.quantity' => ['required', 'numeric', 'min:1'],
            ]);

            $warehouseUuid = Warehouse::where('is_bar_warehouse', true)->firstOrFail()->uuid;

            $stockErrors = [];

            foreach ($validated['drinks'] as $drink) {
                $product = Product::find($drink['product_uuid']);
                $requiredQuantity = $drink['quantity'];

                // 🔹 Quantité en stock dans l'entrepôt bar
                $pointStock = (float) ProductPoint::where('produit_uuid', $drink['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->value('quantity') ?? 0;

                if ($requiredQuantity > $pointStock) {
                    $stockErrors[] = [
                        'product_uuid' => $drink['product_uuid'],
                        'product_name' => $product?->name ?? 'Inconnu',
                        'quantity_required' => $requiredQuantity,
                        'quantity_in_stock' => $pointStock,
                    ];
                }
            }

            // 🔹 Retourner le résultat
            if (!empty($stockErrors)) {
                $messages = array_map(fn($err) => "Boisson « {$err['product_name']} » insuffisante (stock : {$err['quantity_in_stock']})", $stockErrors);

                return response()->json([
                    'status' => 'error',
                    'message' => implode(' | ', $messages),
                    'details' => $stockErrors,
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Stock suffisant pour toutes les boissons',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation exception', $e->errors());
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Exception in check bar stock', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la vérification du stock des boissons.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::show
     * @permission_desc Afficher les détails d'une commandes
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

                // items : dernier ajouté en premier
                'items' => function ($query) {
                    $query->orderByDesc('created_at');
                },

                'items.menu',

                // drinks : dernier ajouté en premier
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
     * @permission OrderMenuRestaurantController::transferOrderMenuRestaurant
     * @permission_desc Transférer une commande
     */
    public function transferOrderMenuRestaurant(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'received_by' => ['required', 'exists:users,id'],
        ], [
            'received_by.required' => "L'utilisateur destinataire est obligatoire.",
            'received_by.exists'   => "L'utilisateur sélectionné est introuvable.",
        ]);

        DB::beginTransaction();

        try {
            $orderMenu = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();
            $recipient = User::findOrFail($validated['received_by']);

            $orderMenu->update([
                'status'         => MenuOrderStatus::TRANSFERED->value,
                'received_by'    => $recipient->id,
                'transfered_at'  => now(),
                'transfered_by'  => $auth->id,
                'updated_by'     => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Commande transférée avec succès.',
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du transfert de la commande.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::ChangeOrderMenuRestaurantInPreparation
     * @permission_desc Mettre une commande en cours de préparation
     */
    public function ChangeOrderMenuRestaurantInPreparation(Request $request, $uuid)
    {
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
        $orderMenu = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();
        $orderMenu->update([
            'status' => MenuOrderStatus::IN_PREPARATION->value,
            'updated_by' => auth()->id(),
        ]);
        return response()->json([
            'message' => "La commande a été mise en préparation avec succès.",
            'orderMenu' => $orderMenu
        ]);
    }



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::RejectOrderMenuRestaurant
     * @permission_desc Rejetter une commande
     */
    public function RejectOrderMenuRestaurant(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_rejected' => 'required|string|max:1000',
        ], [
            'reason_rejected.required' => "La raison du rejet est obligatoire.",
            'reason_rejected.string'   => "La raison doit être une chaîne de caractères.",
            'reason_rejected.max'      => "La raison ne doit pas dépasser 1000 caractères.",
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        try {
            $order->update([
                'status'          => MenuOrderStatus::REJECTED->value,
                'reason_rejected' => $validated['reason_rejected'],
                'rejected_at'     => now(),
                'rejected_by'     => $auth->id,
                'updated_by'      => $auth->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Commande rejetée avec succès.',
                'data'    => $order,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du rejet de la commande.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::RejectItemForOrder
     * @permission_desc Rejetter les plats et les boissons selectionnées d'une commande
     */
    public function RejectItemForOrder(Request $request, $uuid)
    {
        $auth = auth()->user();

        // 🔹 Validation
        $validated = $request->validate([
            'reason_rejected' => 'required|string|max:1000',
            'selected_items'  => 'required|array', // uuid des items à rejeter
        ], [
            'reason_rejected.required' => "La raison du rejet est obligatoire.",
            'reason_rejected.string'   => "La raison doit être une chaîne de caractères.",
            'reason_rejected.max'      => "La raison ne doit pas dépasser 1000 caractères.",
            'selected_items.required'  => "Vous devez sélectionner au moins un élément.",
            'selected_items.array'     => "Les éléments sélectionnés doivent être un tableau.",
        ]);

        // 🔹 Récupérer la commande
        $order = OrderMenuRestaurant::where('uuid', $uuid)->with(['items', 'drinks'])->firstOrFail();

        $now = now();

        $order->items->whereIn('uuid', $validated['selected_items'])
            ->each(function($item) use ($auth, $now, $validated) {
                $item->update([
                    'is_rejected'   => true,
                    'rejected_by'   => $auth->id,
                    'rejected_at'   => $now,
                    'reason'        => $validated['reason_rejected'],
                    'status'        => OrderMenuRestaurantItemStatus::REJECTED->value,
                ]);
            });

        // 🔹 Mettre à jour les boissons
        $order->drinks->whereIn('uuid', $validated['selected_items'])
            ->each(function($drink) use ($auth, $now, $validated) {
                $drink->update([
                    'is_rejected'   => true,
                    'rejected_by'   => $auth->id,
                    'rejected_at'   => $now,
                    'reason'        => $validated['reason_rejected'],
                    'status'        => OrderMenuRestaurantItemStatus::REJECTED->value,
                ]);
            });


        $order->update([
            'status' => MenuOrderStatus::REJECTED->value,
            'reason_rejected' => $validated['reason_rejected'],
            'rejected_at'     => now(),
            'rejected_by'     => $auth->id,
            'updated_by'      => $auth->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Les éléments sélectionnés ont été rejetés avec succès.',
            'order'   => $order,
        ]);
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



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateMenusForOrder
     * @permission_desc Mettre en prêt les plats d'une commande
     */
    public function validateMenusForOrder(Request $request, string $uuid)
    {
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
                $remainingQty = max(0, $item->quantity_exactly - $item->quantity_delivered);

                if ($qtyToDeliver > $remainingQty) {
                    return response()->json([
                        'success' => false,
                        'message' => "Impossible de livrer {$qtyToDeliver} menus pour l'item, quantité restante : {$remainingQty}"
                    ], 422);
                }

                // 🔹 Livrer proportionnellement
                $virtualLogs = $this->deliverMenuVirtualsProportional(
                    $order->uuid,
                    $item->uuid,
                    $qtyToDeliver,
                    $totalOrdered
                );

                // 🔹 Mettre à jour l'item
                $newDeliveredTotal = $item->quantity_delivered + $qtyToDeliver;
                $newRemaining = max(0, $totalOrdered - $newDeliveredTotal);

                if ($newRemaining <= 0) {
                    $itemStatus = OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value;
                    $hasBeenValidated = true;
                } else {
                    $itemStatus = $item->status === OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        ? OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        : OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value;

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
                    'virtuals' => $virtualLogs,
                ];
            }

            // 🔹 Statut global de la commande pour les menus
            $itemsStatus = array_column($allDeliveryLogs, 'status_item');
            $orderStatus = !empty($itemsStatus)
                ? (count(array_unique($itemsStatus)) === 1 ? $itemsStatus[0] : OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value)
                : OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;

            $this->refreshOrderStatus($order);
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
     * @permission_desc Mettre en prêt les boissons d'une commande
     */
    public function validateDrinksForOrder(Request $request, string $uuid)
    {
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

                // 🔹 Livrer proportionnellement
                $virtualLogs = $this->deliverDrinkVirtualsProportional(
                    $order->uuid,
                    $item->uuid,
                    $qtyToDeliver,
                    $totalOrdered
                );

                // 🔹 Mettre à jour l'item
                $newDeliveredTotal = $item->quantity_delivered + $qtyToDeliver;
                $newRemaining = max(0, $totalOrdered - $newDeliveredTotal);


                if ($newRemaining <= 0) {
                    $itemStatus = OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value;
                    $hasBeenValidated = true;
                } else {
                    $itemStatus = $item->status === OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        ? OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        : OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value;

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
                    'virtuals' => $virtualLogs,
                ];
            }

            // 🔹 Statut global de la commande pour les drinks
            $itemsStatus = array_column($allDeliveryLogs, 'status_item');
            $orderStatus = !empty($itemsStatus)
                ? (count(array_unique($itemsStatus)) === 1 ? $itemsStatus[0] : OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value)
                : OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;

            $this->refreshOrderStatus($order);
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
    protected function deliverDrinkVirtualsProportional(string $orderUuid, string $itemUuid, int $qtyToDeliver, int $totalOrdered): array
    {
        $virtuals = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
            ->where('item_uuid', $itemUuid)
            ->where('item_type', 'drink')
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
     * @permission OrderMenuRestaurantController::validateAndDeductStockMenus
     * @permission_desc Mettre en servie les plats d'une commande marqué prêt
     */
    public function validateAndDeductStockMenus(Request $request ,string $orderUuid)
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

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['items.virtuals'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $stockLogs = [];

            foreach ($order->items as $item) {
                $virtuals = $item->virtuals->where('item_type', 'menu');
                $hasProcessedVirtuals = false;

                foreach ($virtuals as $v) {
                    $toDeduct = (int) $v->quantity_delivered;

                    if ($toDeduct <= 0) continue;

                    $hasProcessedVirtuals = true;

                    // 1. Déduction du Stock Réel
                    $produitPoint = ProductPoint::where('produit_uuid', $v->product_uuid)
                        ->where('point_uuid', $warehouse->uuid)
                        ->first();

                    if ($produitPoint) {
                        $stockBefore = $produitPoint->quantity;
                        $produitPoint->quantity = max(0, $produitPoint->quantity - $toDeduct);
                        $produitPoint->save();


                        $v->quantity_delivered = 0;

                        $v->status = ($v->quantity_reserved <= 0)
                            ? OrderMenuRestaurantItemStatus::DELIVERED->value
                            : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                        $v->save();

                        $stockLogs[] = [
                            'product' => $v->product_uuid,
                            'before' => $stockBefore,
                            'after' => $produitPoint->quantity,
                            'deducted' => $toDeduct
                        ];
                    }
                }

                if ($hasProcessedVirtuals) {
                    // On met à jour le statut de l'item basé sur sa quantité restante
                    $item->status = ($item->quantity <= 0)
                        ? OrderMenuRestaurantItemStatus::DELIVERED->value
                        : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                    $item->quantity_delivered = 0;
                    $item->save();
                }
            }

            $this->refreshOrderStatus($order);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validation éffectuée avec succes!.',
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
     * @permission OrderMenuRestaurantController::cancelMenuValidation
     * @permission_desc Rejetter les plats d'une commande marqué prêt
     */
    public function cancelMenuValidation(Request $request ,string $orderUuid)
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

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['items.virtuals'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $restorationLogs = [];

            foreach ($order->items as $item) {
                $toRestore = (int) $item->quantity_delivered;

                if ($toRestore <= 0) continue;

                $item->quantity += $toRestore;
                $item->quantity_delivered -= $toRestore;
                $item->quantity_final_used -= $toRestore;


                if ($item->quantity_delivered > 0) {
                    $item->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                } else {
                    $item->status = ($item->quantity_final_used > 0)
                        ? OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        : OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;
                }

                $item->save();

                $virtuals = $item->virtuals->where('item_type', 'menu');

                foreach ($virtuals as $v) {

                    $quantiteLivree = (int) $v->quantity_delivered;

                    if ($quantiteLivree <= 0) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CAS 1️⃣ : l'item avait été VALIDÉ → on restaure l'entrepôt
                    |--------------------------------------------------------------------------
                    */
                    if ($v->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {

                        $produitPoint = ProductPoint::where('produit_uuid', $v->product_uuid)
                            ->where('point_uuid', $warehouse->uuid)
                            ->first();

                        if ($produitPoint) {
                            // 🔹 Restauration du stock physique
                            $produitPoint->quantity += $quantiteLivree;
                            $produitPoint->save();
                        }
                    }

                    $v->quantity_reserved += $quantiteLivree;
                    $v->quantity_delivered = 0;

                    $v->status = OrderMenuRestaurantItemStatus::PENDING->value;

                    $v->save();

                    $restorationLogs[] = [
                        'product' => $v->product_uuid,
                        'restored_qty' => $quantiteLivree,
                        'warehouse_restored' => $v->status === OrderMenuRestaurantItemStatus::DELIVERED->value
                    ];
                }
            }

            $this->refreshOrderStatus($order);

            $order->save();


            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation éffectuée avec succès!.',
                'logs' => $restorationLogs
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation : ' . $e->getMessage()
            ], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateAndDeductStockDrinks
     * @permission_desc Mettre en servie les boissons d'une commande marqué prête
     */
    public function validateAndDeductStockDrinks(Request $request ,string $orderUuid)
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

        DB::beginTransaction();
        try {
            // 1. Charger la commande avec ses boissons uniquement
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['drinks'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $stockLogs = [];

            foreach ($order->drinks as $drink) {
                // On récupère la quantité marquée comme livrée dans la table des boissons
                $toDeduct = (int) $drink->quantity_delivered;

                if ($toDeduct <= 0) continue;

                // 2. Déduction du Stock Réel du produit (la boisson)
                $produitPoint = ProductPoint::where('produit_uuid', $drink->product_uuid)
                    ->where('point_uuid', $warehouse->uuid)
                    ->first();

                if ($produitPoint) {
                    $stockBefore = $produitPoint->quantity;
                    $produitPoint->quantity = max(0, $produitPoint->quantity - $toDeduct);
                    $produitPoint->save();

                    $drink->quantity_delivered = 0;

                    $drink->status = ($drink->quantity <= 0)
                        ? OrderMenuRestaurantItemStatus::DELIVERED->value
                        : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;

                    $drink->save();

                    $stockLogs[] = [
                        'drink_uuid' => $drink->uuid,
                        'product' => $drink->product_uuid,
                        'before' => $stockBefore,
                        'after' => $produitPoint->quantity,
                        'deducted' => $toDeduct
                    ];
                }
            }

            $this->refreshOrderStatus($order);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validation éffectuée avec succès.',
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
     * @permission OrderMenuRestaurantController::cancelDrinkValidation
     * @permission_desc Rejetter les boissons d'une commande marqué comme prêt
     */
    public function cancelDrinkValidation(Request $request ,string $orderUuid)
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
        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['drinks'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $restorationLogs = [];

            foreach ($order->drinks as $drink) {
                $toRestore = (int) $drink->quantity_delivered;

                if ($toRestore <= 0) continue;

                // 🔹 Restaure la quantité dans l'item
                $drink->quantity += $toRestore;
                $drink->quantity_delivered -= $toRestore;
                $drink->quantity_final_used -= $toRestore;



                if ($drink->quantity_delivered > 0) {
                    $drink->status = OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                } else {
                    $drink->status = ($drink->quantity_final_used > 0)
                        ? OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                        : OrderMenuRestaurantItemStatus::NOT_DELIVERED->value;
                }

                $drink->save();

                /*
                |--------------------------------------------------------------------------
                | CAS 1️⃣ : l'item avait été VALIDÉ → on restaure l'entrepôt
                |--------------------------------------------------------------------------
                */
                if ($toRestore > 0) {
                    $produitPoint = ProductPoint::where('produit_uuid', $drink->product_uuid)
                        ->where('point_uuid', $warehouse->uuid)
                        ->first();

                    if ($produitPoint) {
                        $produitPoint->quantity += $toRestore;
                        $produitPoint->save();
                    }

                    $restorationLogs[] = [
                        'product' => $drink->product_uuid,
                        'restored_qty' => $toRestore,
                        'warehouse_restored' => true
                    ];
                }
            }

            $this->refreshOrderStatus($order);

            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation des validations des boissons effectuée avec succès !',
                'logs' => $restorationLogs
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation : ' . $e->getMessage()
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
                    OrderMenuRestaurantItemStatus::PENDING->value
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
            $order->items()
                ->whereIn('uuid', $items->pluck('uuid'))
                ->delete();

            $this->refreshOrderStatus($order->fresh());

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
     * @permission_desc Modifier la quantité des plats non servis d'une commande
     */
    public function updateMenuItemQuantity(Request $request, string $orderUuid)
    {
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

                if (!$item) {
                    continue;
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

                // 🔹 Mise à jour
                $newQtyTotal = $currentQty - $reduceQty;

                $item->quantity_exactly = $newQtyTotal;
                $item->quantity = $newQtyTotal;
                $item->total_price = $newQtyTotal * $item->unit_price;

                $item->status = $newQtyTotal == $deliveredQty
                    ? OrderMenuRestaurantItemStatus::DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;

                $item->save();
                $updatedItems[] = $item;
            }

            // 🔹 Rafraîchir statut de la commande
            $this->refreshOrderStatus($order->fresh());

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
     * @permission_desc Modifier la quantité des boissons non servis d'une commande
     */
    public function updateDrinksQuantity(Request $request, string $orderUuid)
    {
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
            ->with('drinks')
            ->firstOrFail();

        DB::beginTransaction();

        try {

            $updatedDrinks = [];

            foreach ($request->items as $itemData) {

                $drink = $order->drinks->where('uuid', $itemData['uuid'])->first();

                if (!$drink) {
                    continue;
                }

                $deliveredQty = (int) $drink->quantity_final_used;
                $currentQty   = (int) $drink->quantity_exactly;
                $reduceQty    = (int) $itemData['new_quantity'];

                $maxReducible = $currentQty - $deliveredQty;

                // ❌ Interdit de retirer plus que ce qui reste
                if ($reduceQty > $maxReducible) {
                    throw new \Exception(
                        "Impossible : vous ne pouvez retirer que {$maxReducible} quantités pour la boisson  {$drink->product->name}."
                    );
                }

                // 🔹 Calcul du nouveau total
                $newTotalQty = $currentQty - $reduceQty;

                $drink->quantity_exactly = $newTotalQty;
                $drink->quantity = $newTotalQty;
                $drink->total_price = $newTotalQty * $drink->unit_price;

                $drink->status = $newTotalQty == $deliveredQty
                    ? OrderMenuRestaurantItemStatus::DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;

                $drink->save();

                $updatedDrinks[] = $drink;
            }

            // 🔹 Rafraîchir statut global de la commande
            $this->refreshOrderStatus($order->fresh());

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

        // Tous servis
        $allServed = $allItems->every(
            fn($i) => $i->status === OrderMenuRestaurantItemStatus::DELIVERED->value
        );

        // Au moins un servi
        $anyServed = $allItems->some(
            fn($i) => in_array(
                $i->status,
                [
                    OrderMenuRestaurantItemStatus::DELIVERED->value,
                    OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
                ]
            )
        );

        // Tous prêts pour service
        $allReady = $allItems->every(
            fn($i) => $i->status === OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value
        );

        // Au moins un prêt
        $anyReady = $allItems->some(
            fn($i) => $i->status === OrderMenuRestaurantItemStatus::DELIVERED_IN_PREPARATION->value
        );

        if ($allServed) {

            $order->status = MenuOrderStatus::COMPLETED->value;

        } elseif ($anyServed) {

            $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;

        } elseif ($allReady) {

            $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;

        } elseif ($anyReady) {

            $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;

        } else {

            $order->status = MenuOrderStatus::IN_PREPARATION->value;
        }

        $order->save();
    }



    public function save_facture(Request $request,string $uuid)
    {
        $auth = auth()->user();
        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();
        $invoice = InvoiceForMenuOrder::where('order_menu_restaurant_uuid', $order->uuid)->first();

        if($invoice) {
            $invoice->amount = $request->input('amount', $invoice->amount);
            $invoice->type = $request->input('type', $invoice->type);
            $invoice->updated_by = $auth->id;
            $invoice->save();
        } else {

            $invoice = InvoiceForMenuOrder::create([
                'order_menu_restaurant_uuid' => $order->uuid,
                'amount' => $request->input('amount', 0),
                'type' => $request->input('type', 1),
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'date_fact' => now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Facture enregistrée avec succès',
            'invoice' => $invoice
        ]);

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

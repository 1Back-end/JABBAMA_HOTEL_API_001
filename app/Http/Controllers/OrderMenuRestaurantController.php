<?php

namespace App\Http\Controllers;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\TypeClientsForPaiment;
use App\Enums\VirtualOrderMenuRestaurantStatus;
use App\Models\DrinksVirtualTemp;
use App\Models\InvoiceForMenuOrder;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\MenuRestaurant;
use App\Models\MenuVirtualTemp;
use App\Models\OrderMenuItemStatus;
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

            $warehouseUuid = $validated['warehouse_uuid']
                ?? optional(Warehouse::where('is_used_for_restaurant', true)->first())->uuid;

            if (!$warehouseUuid) {
                throw new \Exception("Aucun entrepôt configuré");
            }

            // ✅ Vérification stock
            if ($errors = $this->verifyMenuStock($validated['menus'], $warehouseUuid)) {
                return response()->json(['status'=>'error','message'=>'Stock insuffisant menus','details'=>$errors],422);
            }

            if (!empty($validated['drinks'])) {
                if ($errors = $this->verifyBarStock($validated['drinks'])) {
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

                \App\Models\OrderMenuItemStatus::create([
                    'order_menu_restaurant_item_uuid' => $orderItem->uuid,
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value, // seulement TRANSFERRED
                    'quantity' => $orderItem->quantity,
                    'quantity_exactly' => $orderItem->quantity,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
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
                        'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
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
            OrderMenuItemStatus::whereIn('order_menu_restaurant_item_uuid', $order->items()->pluck('uuid'))->delete();

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
                    'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                    'is_last_items' => true
                ]);

                OrderMenuItemStatus::create([
                    'order_menu_restaurant_item_uuid' => $item->uuid,
                    'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    'quantity' => $item->quantity,
                    'quantity_exactly' => $item->quantity,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
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
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::TRANSFERRED->value,
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

    public function checkStatusForMenus(Request $request, string $uuid)
    {
        $order = OrderMenuRestaurant::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'menus' => ['required', 'array'],
            'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
            'menus.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated['menus'] as $m) {

            $existingItem = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)
                ->where('menus_restaurant_uuid', $m['menus_restaurant_uuid'])
                ->first();

            if (!$existingItem) continue;

            $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);

            // 🔸 IN_PREPARATION
            if ($existingItem->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value) {
                if ($m['quantity'] < $existingItem->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Impossible de réduire \"{$menu->name}\" en préparation.",
                    ], 422);
                }
            }

            // 🔸 REJECTED_FOR_NEW_UPDATE
            if ($existingItem->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value) {
                if ($m['quantity'] < $existingItem->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Impossible de réduire \"{$menu->name}\" (rejet du prêt).",
                    ], 422);
                }
            }

            // DELIVERED / PARTIAL_DELIVERED → on ne peut que augmenter
            if (in_array($existingItem->status, [
                OrderMenuRestaurantItemStatus::DELIVERED->value,
                OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
            ])) {
                // 🔥 bloquer la réduction
                if ($m['quantity'] < $existingItem->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Réduction impossible : \"{$menu->name}\" est déjà servie ou partiellement servie. Vous ne pouvez qu'augmenter la quantité.",
                    ], 422);
                }
            }

            // 🔸 REJECTED
            if (in_array($existingItem->status, [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ])) {
                if ($m['quantity'] > $existingItem->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Impossible d’augmenter \"{$menu->name}\" rejeté.",
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

            $drink = Product::findOrFail($d['product_uuid']);

            // 🔸 IN_PREPARATION
            if ($existingDrink->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value) {
                if ($d['quantity'] < $existingDrink->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Impossible de réduire \"{$drink->name}\" en préparation.",
                    ], 422);
                }
            }

            // 🔸 REJECTED_FOR_NEW_UPDATE
            if ($existingDrink->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value) {
                if ($d['quantity'] < $existingDrink->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Impossible de réduire \"{$drink->name}\" (rejet du prêt).",
                    ], 422);
                }
            }

            // DELIVERED / PARTIAL_DELIVERED → on ne peut que augmenter
            if (in_array($existingDrink->status, [
                OrderMenuRestaurantItemStatus::DELIVERED->value,
                OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
            ])) {
                if ($d['quantity'] < $existingDrink->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Réduction impossible : \"{$drink->name}\" est déjà servie ou partiellement servie. Vous ne pouvez qu'augmenter la quantité.",
                    ], 422);
                }
            }

            // 🔸 REJECTED
            if (in_array($existingDrink->status, [
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::NEW_REJECTED->value
            ])) {
                if ($d['quantity'] > $existingDrink->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Impossible d’augmenter \"{$drink->name}\" rejeté.",
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

            $warehouseUuid = $validated['warehouse_uuid']
                ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

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


            foreach ($validated['menus'] ?? [] as $m) {

                $menu = MenuRestaurant::findOrFail($m['menus_restaurant_uuid']);
                $unitPrice = $m['unit_price'] ?? $menu->price ?? 0;
                $isLastItem = $m['is_last_items'] ?? false;

                if ($isLastItem) {
                    continue;
                }

                $existingItem = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)
                    ->where('menus_restaurant_uuid', $menu->uuid)
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | 🔥 CAS 1 : ITEM EXISTE
                |--------------------------------------------------------------------------
                */
                if ($existingItem) {
                    $newQty = $m['quantity'];
                    $oldQty = $existingItem->quantity;

                    $isRejected = in_array($existingItem->status, [
                        OrderMenuRestaurantItemStatus::REJECTED->value,
                        OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                    ]);

                    $isRejectedForUpdate = $existingItem->status ===
                        OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;

                    $statusesToTransfer = in_array($existingItem->status, [
                        OrderMenuRestaurantItemStatus::DELIVERED->value,
                        OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
                    ]);

                    $isTransferred = $existingItem->status === OrderMenuRestaurantItemStatus::TRANSFERRED->value;

                    // ⚡ Évite traitement inutile si quantité identique et pas de cas spéciaux
                    if ($newQty == $oldQty && !$isRejected && !$isRejectedForUpdate && !$statusesToTransfer && !$isTransferred) {
                        continue;
                    }

                    // 🔹 Gestion des différents cas selon le statut
                    if ($isTransferred) {
                        $response = $this->handleTransferred($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($existingItem->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value) {
                        $response = $this->handleInPreparation($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($statusesToTransfer) {
                        $response = $this->handleDeliveredOrPartial($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($isRejectedForUpdate) {
                        $response = $this->handleRejectedForUpdate($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($isRejected) {
                        $response = $this->handleRejected($existingItem, $m, $menu, $order, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    $this->updateExistingMenuItem($existingItem, $menu, $order, $newQty, $unitPrice, $auth);
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

                    $isRejected = in_array($existingDrink->status, [
                        OrderMenuRestaurantItemStatus::REJECTED->value,
                        OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                    ]);

                    $isRejectedForUpdate = $existingDrink->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;

                    $statusesToTransferDrinks = in_array($existingDrink->status, [
                        OrderMenuRestaurantItemStatus::DELIVERED->value,
                        OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value,
                    ]);

                    $isTransferredDrinks = $existingDrink->status === OrderMenuRestaurantItemStatus::TRANSFERRED->value;

                    // ⚡ éviter traitement inutile
                    if ($newQty == $oldQty && !$isRejected && !$isRejectedForUpdate && !$statusesToTransferDrinks && !$isTransferredDrinks) {
                        continue;
                    }

                    if ($isRejected) {
                        $response = $this->handleRejectedDrink($existingDrink, $d, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if ($isRejectedForUpdate) {
                        $response = $this->handleRejectedForUpdateDrink($existingDrink, $d, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if($statusesToTransferDrinks){
                        $response = $this->handleDeliveredOrPartialDrink($existingDrink, $d, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if($existingDrink->status === OrderMenuRestaurantItemStatus::IN_PREPARATION->value){
                        $response = $this->handleInPreparationDrink($existingDrink, $d, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    if($isTransferredDrinks){
                        $response = $this->handleTransferredDrink($existingDrink, $d, $unitPrice, $auth);
                        if ($response) return $response;
                        continue;
                    }

                    $this->updateExistingDrink($existingDrink, $d, $product, $unitPrice, $auth);
                }

                // ⚡ Création d’un nouveau drink
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

    private function updateExistingDrink(OrderRestaurantDrink $drink, array $data, Product $product, float $unitPrice, $auth) {
        $oldQty = (int) $drink->quantity;
        $newQty = (int) $data['quantity'];

        // ⚡ rien à faire si même quantité
        if ($newQty === $oldQty) {
            return $drink;
        }

        // 🔥 calcul diff
        $diffQty = $newQty - $oldQty;

        // 🔹 update drink
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 virtual basé sur PRODUCT
        $this->createVirtualDrinkItems($product, $drink, $diffQty, $auth);

        return $drink;
    }
    private function createNewDrink(OrderRestaurantDrink $drink, array $data,  Product $product,float $unitPrice, $auth): OrderRestaurantDrink
    {
        // 🔥 récupérer le produit
        $product = Product::findOrFail($data['product_uuid']);

        $drink = OrderRestaurantDrink::create([
            'order_menu_restaurant_uuid' => $drink->uuid,
            'product_uuid' => $product->uuid,
            'quantity' => $data['quantity'],
            'quantity_exactly' => $data['quantity'],
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $data['quantity'],
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_new_items' => true,
            'is_last_items' => false,
        ]);
        $this->createVirtualDrinkItems($product, $drink, $data['quantity'], $auth, true, false);

        return $drink;
    }
    private function handleRejectedDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth)
    {
        $newQty = $data['quantity'];
        $oldQty = $drink->quantity;

        $product = $drink->product;

        if ($newQty > $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible d’augmenter une boisson rejetée.",
            ], 422);
        }

        if ($newQty == $oldQty) {
            $drink->update([
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'updated_by' => $auth->id,
            ]);
            return null;
        }


        $deliveredQty = (int) $drink->quantity_final_used;
        $currentQty   = (int) $drink->quantity_exactly;
        $reduceQty    = (int) $drink->quantity;

        $maxReducible = $currentQty - $deliveredQty;

        if ($reduceQty > $maxReducible) {
            throw new \Exception(
                "Action impossible. Quantité trop élevée."
            );
        }

        $diff = $currentQty - $newQty;
        $diff_rest = $reduceQty - $newQty;

        $newStatus = $newQty === $deliveredQty
            ? OrderMenuRestaurantItemStatus::DELIVERED->value
            : OrderMenuRestaurantItemStatus::TRANSFERRED->value;

        // 6. 📝 MISE À JOUR
        $drink->update([
            'quantity' => $diff_rest,
            'quantity_exactly' => $diff,
            'total_price' => $diff * $drink->unit_price,
            'status' => $newStatus,
            'updated_by' => $auth->id,
        ]);
        $this->createVirtualDrinkItems($product, $drink, $diff, $auth, true, false);

        return null;
    }

    private function handleRejectedForUpdateDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth)
    {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity;

        // 🔥 récupérer le produit
        $product = $drink->product;

        // ❌ réduction interdite
        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$product->name}\" (rejet pour mise à jour). Vous ne pouvez qu’augmenter la quantité.",
            ], 422);
        }

        // ⚡ aucune modification → juste changer statut
        if ($newQty === $oldQty) {
            $drink->update([
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'updated_by' => $auth->id,
            ]);
            return null;
        }

        // 🔹 différence à ajouter
        $diffQty = $newQty - $oldQty;

        // 🔹 update drink
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 création / mise à jour virtual drinks
        $this->createVirtualDrinkItems($product, $drink, $diffQty, $auth, true, false);

        return null;
    }

    private function handleDeliveredOrPartialDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth)
    {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity;
        $diffQty = $newQty - $oldQty;

        $product = $drink->product;

        // ❌ réduction interdite
        if ($diffQty < 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$product->name}\" déjà servi ou partiellement servi.",
            ], 422);
        }

        // ⚡ aucune modification → rien à faire
        if ($diffQty === 0) {
            return null;
        }

        // 🔹 mettre à jour le drink
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $drink->quantity_exactly + $diffQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 créer / mettre à jour virtual drinks
        $this->createVirtualDrinkItems($product, $drink, $diffQty, $auth, true, false);

        return null;
    }

    private function handleInPreparationDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth)
    {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity;
        $product = $drink->product;

        // ❌ réduction interdite
        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$product->name}\" en préparation.",
            ], 422);
        }

        // ⚡ aucune modification
        if ($newQty === $oldQty) return null;

        $diffQty = $newQty - $oldQty;

        // 🔹 mise à jour du drink
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 créer / mettre à jour virtual drinks
        $this->createVirtualDrinkItems($product, $drink, $diffQty, $auth, true, false);

        return null;
    }

    private function handleTransferredDrink(OrderRestaurantDrink $drink, array $data, float $unitPrice, $auth)
    {
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $drink->quantity;
        $product = $drink->product;

        if ($newQty === $oldQty) return null;

        $diffQty = $newQty - $oldQty;

        // 🔹 mise à jour du drink
        $drink->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);

        // 🔥 créer / mettre à jour virtual drinks
        $this->createVirtualDrinkItems($product, $drink, $diffQty, $auth, true, false);

        return null;
    }
    private function createVirtualDrinkItems(Product $product, OrderRestaurantDrink $drink, int $quantity, $auth, bool $isNew = true, bool $isReturn = false)
    {
        // 🔹 gérer le signe (retour stock)
        $qty = $isReturn ? -$quantity : $quantity;

        VirtualOrderMenuRestaurant::create([
            'orders_menu_restaurant_uuid' => $drink->order_menu_restaurant_uuid,
            'item_uuid' => $drink->uuid,
            'product_uuid' => $product->uuid, // 🔥 maintenant on utilise product
            'quantity_reserved' => $qty,
            'quantity_exactly' => $qty,
            'quantity_delivered_exactly' => $qty,
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
            'is_new_items' => $isNew,
            'is_last_items' => false,
        ]);
    }





    private function updateExistingMenuItem(OrderMenuRestaurantItem $item, MenuRestaurant $menu, OrderMenuRestaurant $order, int $newQty, float $unitPrice, $auth) {
        $oldQty = $item->quantity;

        // ⚡ mettre à jour l'item existant
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 créer / mettre à jour virtual items
        $diffQty = $newQty - $oldQty;
        $this->createVirtualItems($menu, $order, $item, $diffQty, $auth);

        return $item;
    }

    private function createNewMenuItem(array $m, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth): OrderMenuRestaurantItem
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
            'is_last_items' => false,
        ]);

        // 🔹 Appel à la fonction pour créer les virtual items
        $this->createVirtualItems($menu, $order, $item, $m['quantity'], $auth);

        return $item;
    }
    private function createVirtualItems($menu, $order, $item, $quantity, $auth, $isNew = true, $isReturn = false) {
        $components = MenuOrderItem::where('menus_restaurant_uuid', $menu->uuid)->get();

        foreach ($components as $comp) {

            $qty = $quantity * $comp->quantity_used;

            if ($isReturn) {
                $qty = -$qty;
            }

            VirtualOrderMenuRestaurant::create([
                'orders_menu_restaurant_uuid' => $order->uuid,
                'item_uuid' => $item->uuid,
                'product_uuid' => $comp->product_uuid,
                'quantity_reserved' => $qty,
                'quantity_exactly' => $qty,
                'quantity_delivered_exactly' => $qty,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'is_new_items' => $isNew,
                'is_last_items' => false
            ]);
        }
    }

    private function handleRejected(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth)
    {
        $newQtyRequested = (int) $data['quantity']; // Ce que l'utilisateur veut (ex: 5)
        $oldQtyActive = (int) $item->quantity; // Ce qui était actif avant rejet (ex: 1)
        $currentTotal = (int) $item->quantity_exactly; // Le total actuel (ex: 5)

        // 1. Récupération du stock rejeté actuel
        $rejectedStatus = $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::REJECTED->value)
            ->first();
        $qtyCurrentlyRejected = $rejectedStatus ? $rejectedStatus->quantity : 0;

        // --- LOGIQUE DE VALIDATION D'ORIGINE ---
        if ($newQtyRequested > $currentTotal) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible d’augmenter \"{$menu->name}\" rejeté. Vous ne pouvez que réduire la quantité.",
            ], 422);
        }

        // --- CAS A : RÉ-APPROBATION SIMPLE (5 -> 5) ---
        if ($newQtyRequested == $currentTotal) {
            if ($qtyCurrentlyRejected > 0) {
                // On déplace tout du REJETÉ vers le TRANSFÉRÉ
                $this->transferBetweenStatuses(
                    $item,
                    OrderMenuRestaurantItemStatus::REJECTED->value,
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    $qtyCurrentlyRejected,
                    $auth
                );
            }

            $item->update([
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'is_rejected' => false,
                'updated_by' => $auth->id,
            ]);
            return null;
        }

        // --- CAS B : RÉDUCTION DE LA COMMANDE (5 -> 3) ---
        $deliveredQty = (int) $item->quantity_final_used;
        $maxReducible = $currentTotal - $deliveredQty;

        // Votre calcul de différence d'origine
        $diff_to_remove = $currentTotal - $newQtyRequested;

        if ($diff_to_remove > $maxReducible) {
            throw new \Exception(
                "Impossible : vous ne pouvez retirer que {$maxReducible} quantité(s) pour le menu {$item->menu->name}."
            );
        }

        // Gestion des statuts pour la réduction
        // On doit d'abord "annuler" le rejet pour la partie qui revient en circuit
        // La quantité qui revient est : Nouvelle Quantité - Ce qui était déjà en cours/prépa
        $qtyToRestore = max(0, $newQtyRequested - ($currentTotal - $qtyCurrentlyRejected));

        if ($qtyToRestore > 0) {
            $this->transferBetweenStatuses(
                $item,
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                $qtyToRestore,
                $auth
            );
        }

        // Nettoyage : On supprime définitivement le surplus qui était dans Rejected
        if ($rejectedStatus) {
            $remainingInRejected = $rejectedStatus->fresh()->quantity ?? 0;
            if ($remainingInRejected > 0) {
                $rejectedStatus->delete(); // On supprime le reste car la commande est réduite
            }
        }

        $newStatus = $newQtyRequested === $deliveredQty
            ? OrderMenuRestaurantItemStatus::DELIVERED->value
            : OrderMenuRestaurantItemStatus::TRANSFERRED->value;

        // Mise à jour finale de l'item
        $item->update([
            'quantity' => $newQtyRequested, // Ajustement stock réel
            'quantity_exactly' => $newQtyRequested,
            'total_price' => $newQtyRequested * $item->unit_price,
            'status' => $newStatus,
            'is_rejected' => false,
            'updated_by' => $auth->id,
        ]);

        // Recréation des ingrédients pour la nouvelle quantité
        $this->createVirtualItems($menu, $order, $item, $newQtyRequested, $auth, false, true);

        return null;
    }

    private function transferBetweenStatuses($item, $fromStatus, $toStatus, $qty, $auth)
    {
        if ($qty <= 0) return;

        // 1. Rechercher le statut source
        $source = $item->statuses()->where('status', $fromStatus)->first();

        // Sécurité : Si la source n'existe pas ou n'a pas assez de quantité, on stoppe
        if (!$source || $source->quantity < $qty) {
            return;
        }

        // 2. Déduire de la source
        $source->decrement('quantity', $qty);

        // Nettoyage : Si le statut tombe à 0, on supprime la ligne pour l'UI Angular
        if ($source->fresh()->quantity <= 0) {
            $source->delete();
        }

        $destination = $item->statuses()->firstOrCreate(
            ['status' => $toStatus],
            [
                'quantity' => 0,
                'created_by' => $auth->id
            ]
        );

        $destination->increment('quantity', $qty);
    }



    private function handleRejectedForUpdate(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = $data['quantity'];
        $oldQty = $item->quantity;

        // ❌ réduction interdite
        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$menu->name}\" (rejet du prêt). Vous ne pouvez qu’augmenter la quantité.",
            ], 422);
        }

        // ⚡ rien à faire
        if ($newQty == $oldQty) {
            // ⚡ On met à jour le statut même si la quantité n’a pas changé
            $item->update([
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'updated_by' => $auth->id,
            ]);
            return null;
        }

        // ✅ augmentation
        $diff = $newQty - $oldQty;

        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 créer virtual items
        $this->createVirtualItems($menu, $order, $item, $diff, $auth, false, true);

        return null;
    }
    private function handleDeliveredOrPartial(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice,$auth) {
        $newQty = $data['quantity'];
        $oldQty = $item->quantity;

        $diff = $newQty - $oldQty;

        if ($diff < 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$menu->name}\" déjà servie ou partiellement servie. Vous ne pouvez que augmenter la quantité.",
            ], 422);
        }

        // ⚡ rien à faire
        if ($diff == 0) {
            return null;
        }

        // ✅ augmentation
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $item->quantity_exactly + $diff,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔥 créer virtual items
        $this->createVirtualItems($menu, $order, $item, $diff, $auth, false, true);

        return null;
    }

    private function handleInPreparation(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = $data['quantity'];
        $oldQty = $item->quantity;

        // ❌ Réduction interdite
        if ($newQty < $oldQty) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de réduire \"{$menu->name}\" en préparation. Vous ne pouvez que augmenter la quantité.",
            ], 422);
        }

        // ⚡ Rien à faire si quantité identique
        if ($newQty == $oldQty) {
            return null;
        }

        // ✅ Calcul de la différence
        $diff = $newQty - $oldQty;

        $transferredStatus = $item->statuses()->where('status', OrderMenuRestaurantItemStatus::TRANSFERRED->value)->first();
        if (!$transferredStatus) {
            $transferredStatus = $item->statuses()->create([
                'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                'quantity' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);
        }

        $transferredStatus->quantity += $diff;
        $transferredStatus->quantity_exactly += $diff;
        $transferredStatus->updated_by = $auth->id;
        $transferredStatus->save();

        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value,
            'updated_by' => $auth->id,
        ]);

        // 🔹 Création des virtual items pour la quantité supplémentaire
        $this->createVirtualItems($menu, $order, $item, $diff, $auth, true, false);

        return null;
    }
    private function handleTransferred(OrderMenuRestaurantItem $item, array $data, MenuRestaurant $menu, OrderMenuRestaurant $order, float $unitPrice, $auth) {
        $newQty = $data['quantity'];
        $oldQty = $item->quantity;

        // ⚡ Rien à faire si quantité identique
        if ($newQty == $oldQty) {
            return null;
        }

        // ✅ Calcul de la différence
        $diff = $newQty - $oldQty;

        $status = $item->statuses()->firstOrCreate(['status' => OrderMenuRestaurantItemStatus::TRANSFERRED->value], [
                'quantity' => 0,
                'quantity_exactly' => 0,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]
        );

        $status->quantity += $diff;
        $status->quantity_exactly += $diff;

        if ($status->quantity < 0) {
            $status->quantity = 0;
            $status->quantity_exactly = 0;
        }

        $status->updated_by = $auth->id;
        $status->save();

        // 🔹 Mise à jour de l'item
        $item->update([
            'quantity' => $newQty,
            'quantity_exactly' => $newQty,
            'total_price' => $unitPrice * $newQty,
            'updated_by' => $auth->id,
        ]);



        $this->createVirtualItems($menu, $order, $item, $diff, $auth, true, false);

        return null;
    }


    public function verify_to_delete_items_menu(Request $request, $order_uuid, $item_uuid)
    {
        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::where('uuid', $order_uuid)->firstOrFail();

            $item = OrderMenuRestaurantItem::where('menus_restaurant_uuid', $item_uuid)
                ->where('order_menu_restaurant_uuid', $order->uuid)
                ->with('virtuals')
                ->first();

            if (!$item) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Item introuvable.'
                ], 404);
            }

            if ($item->quantity_final_used > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'La suppression est impossible car une quantité a déjà été servie'
                ], 403);
            }

            $allowedStatuses = [
                OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                OrderMenuRestaurantItemStatus::REJECTED->value,
                OrderMenuRestaurantItemStatus::TRANSFERRED->value
            ];

            if (!in_array($item->status, $allowedStatuses)) {

                return response()->json([
                    'status' => 'error',
                    'message' => "La suppression est impossible car le statut actuel est : "
                        . OrderMenuRestaurantItemStatus::safeLabel($item->status) . "."
                ], 403);
            }

            $item->virtuals()->delete();
            $item->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Item supprimé avec succès.'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
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

            MenuVirtualTemp::where('created_by', $auth->id)->delete();

            foreach ($validated['menus'] as $menuInput) {

                $menuItems = MenuOrderItem::where('menus_restaurant_uuid', $menuInput['menus_restaurant_uuid'])->get();

                foreach ($menuItems as $item) {

                    $quantityUsed = $menuInput['quantity'] * $item->quantity_used;

                    MenuVirtualTemp::create([
                        'quantity' => $menuInput['quantity'],
                        'menus_restaurant_uuid' => $menuInput['menus_restaurant_uuid'],
                        'product_uuid' => $item->product_uuid,
                        'quantity_used' => $quantityUsed,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]);
                }
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

            DrinksVirtualTemp::where('created_by', $auth->id)->delete();

            foreach ($validated['drinks'] as $drink) {

                $quantityUsed = $drink['quantity'];

                DrinksVirtualTemp::create([
                    'quantity' => $drink['quantity'],
                    'product_uuid' => $drink['product_uuid'],
                    'quantity_used' => $quantityUsed,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);
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
                'items.rejector',
                'drinks.rejector',
                'items.statuses',

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

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with(['items', 'drinks'])
            ->firstOrFail();

        try {

            DB::transaction(function () use ($order, $validated, $auth) {

                // 🔹 Mise à jour de la commande
                $order->update([
                    'status'          => MenuOrderStatus::REJECTED->value,
                    'reason_rejected' => $validated['reason_rejected'],
                    'rejected_at'     => now(),
                    'rejected_by'     => $auth->id,
                    'updated_by'      => $auth->id,
                ]);

                // 🔹 Rejeter tous les menus
                $order->items()->update([
                    'status' => OrderMenuRestaurantItemStatus::REJECTED->value
                ]);

                // 🔹 Rejeter toutes les boissons
                $order->drinks()->update([
                    'status' => OrderMenuRestaurantItemStatus::REJECTED->value
                ]);

            });

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
     * @permission OrderMenuRestaurantController::rejectMenuItems
     * @permission_desc Rejetter les plats selectionnées d'une commande
     */
    public function rejectMenuItems(Request $request, $uuid)
    {
        $auth = auth()->user();

        // Validation basée sur votre nouveau format : un tableau d'objets
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

                // On récupère la quantité à rejeter envoyée par le front
                $qtyToReject = (int) $selection['quantity_to_deliver'];
                $originalQtyToReject = $qtyToReject; // Sauvegarde pour le log final

                // 1. Déduction en cascade : d'abord TRANSFERRED, puis IN_PREPARATION
                $this->deductFromStatus($item, OrderMenuRestaurantItemStatus::TRANSFERRED->value, $qtyToReject);

                if ($qtyToReject > 0) {
                    $this->deductFromStatus($item, OrderMenuRestaurantItemStatus::IN_PREPARATION->value, $qtyToReject);
                }

                // 2. Enregistrement du rejet dans la table des statuts
                $rejectedStatus = $item->statuses()->firstOrCreate(
                    ['status' => OrderMenuRestaurantItemStatus::REJECTED->value],
                    ['quantity' => 0, 'created_by' => $auth->id]
                );
                $rejectedStatus->increment('quantity', $originalQtyToReject);


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

    /**
     * Fonction helper pour déduire une quantité d'un statut spécifique
     */
    private function deductFromStatus($item, $statusValue, &$qtyToReject)
    {
        $statusRow = $item->statuses()->where('status', $statusValue)->first();
        if ($statusRow && $statusRow->quantity > 0) {
            $deductible = min($qtyToReject, $statusRow->quantity);
            $statusRow->decrement('quantity', $deductible);
            $qtyToReject -= $deductible;

            // Optionnel : supprimer la ligne si qté = 0
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

        $validated = $request->validate([
            'reason_rejected' => 'required|string|max:1000',
            'selected_items'  => 'required|array',
        ]);

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with(['drinks'])
            ->firstOrFail();

        // 🔥 Récupérer les drinks sélectionnés
        $drinks = $order->drinks()
            ->whereIn('uuid', $validated['selected_items'])
            ->get();

        // 🔥 Vérification blocage
        $hasInvalidItem = $drinks->contains(function ($drink) {
            return $drink->status === OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value;
        });

        if ($hasInvalidItem) {

            $statusLabel = OrderMenuRestaurantItemStatus::safeLabel(
                OrderMenuRestaurantItemStatus::PARTIAL_COMPLETED->value
            );

            return response()->json([
                'status'  => 'error',
                'message' => "Impossible de rejeter une boisson avec le statut : {$statusLabel}.",
            ], 422);
        }

        $now = now();

        $drinks->each(function ($el) use ($validated, $auth, $now) {

            $status = $el->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value
                ? OrderMenuRestaurantItemStatus::NEW_REJECTED->value
                : OrderMenuRestaurantItemStatus::REJECTED->value;

            $el->update([
                'is_rejected' => true,
                'rejected_by' => $auth->id,
                'rejected_at' => $now,
                'reason'      => $validated['reason_rejected'],
                'status'      => $status,
            ]);
        });

        $this->refreshOrderStatus($order);

        $order->update([
            'updated_by' => $auth->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Boissons rejetées avec succès.',
        ]);
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

                $qtyRemainingToPrepare = (int) $itemData['quantity_to_deliver'];

                // 1. DÉDUCTION EN CASCADE
                // Liste des statuts sources autorisés pour la mise en cuisine
                $sourceStatuses = [
                    OrderMenuRestaurantItemStatus::TRANSFERRED->value,
                    OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value
                ];

                foreach ($sourceStatuses as $statusType) {
                    if ($qtyRemainingToPrepare <= 0) break;

                    $statusModel = $item->statuses()->where('status', $statusType)->first();

                    if ($statusModel && $statusModel->quantity > 0) {
                        $take = min($qtyRemainingToPrepare, $statusModel->quantity);

                        $statusModel->decrement('quantity', $take);
                        $qtyRemainingToPrepare -= $take;

                        // Nettoyage : si le statut tombe à 0, on le supprime pour l'UI
                        if ($statusModel->fresh()->quantity <= 0) {
                            $statusModel->delete();
                        }
                    }
                }

                // Si on a pu déduire quelque chose, on l'ajoute à la préparation
                $actualProcessed = (int) $itemData['quantity_to_deliver'] - $qtyRemainingToPrepare;

                if ($actualProcessed > 0) {
                    // 2. AJOUT : Bascule vers "En préparation"
                    $prepStatus = $item->statuses()->firstOrCreate(
                        ['status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value],
                        ['quantity' => 0, 'created_by' => $auth->id]
                    );
                    $prepStatus->increment('quantity', $actualProcessed);

                    // 3. Mise à jour de l'item parent
                    $item->update([
                        'status' => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                        'is_rejected' => false, // On enlève le flag de rejet car il repart en cuisine
                        'make_in_preparation_at' => $now,
                        'updated_by' => $auth->id
                    ]);
                }
            }

            $this->refreshOrderStatus($order);
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mise en cuisine validée',
                'order' => $order->fresh(['items.statuses'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::SetDrinksInPreparation
     * @permission_desc Mettre en cours de préparation les boissons selectionnées d'une commande
     */
    public function SetDrinksInPreparation(Request $request, $uuid)
    {
        $auth = auth()->user();

        // 🔹 Validation
        $validated = $request->validate([
            'selected_items' => 'required|array|min:1',
            'selected_items.*' => 'string',
            'password' => 'required|string',
        ], [
            'selected_items.required' => "Vous devez sélectionner au moins une boisson.",
            'selected_items.array'    => "Les boissons sélectionnées doivent être un tableau.",
            'selected_items.min'      => "Sélection invalide.",
            'password.required'       => "Le mot de passe est obligatoire.",
        ]);

        // 🔐 Vérification mot de passe
        if (!Hash::check($validated['password'], $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.',
            ], 403);
        }

        $order = OrderMenuRestaurant::where('uuid', $uuid)
            ->with(['drinks'])
            ->firstOrFail();

        $now = now();

        // 🔹 Update UNIQUEMENT BOISSONS
        $order->drinks()
            ->whereIn('uuid', $validated['selected_items'])
            ->where('status', '!=', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
            ->update([
                'status'     => OrderMenuRestaurantItemStatus::IN_PREPARATION->value,
                'updated_by' => $auth->id,
                'updated_at' => $now,
                'make_in_preparation_at' => $now,
            ]);

        // 🔄 Refresh statut global
        $this->refreshOrderStatus($order);

        // 🔹 Update order
        $order->update([
            'updated_by' => $auth->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Les boissons sont en cours de préparation.',
            'order'   => $order->fresh(['drinks']),
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

    private function updateItemStatusFromPreparation(OrderMenuRestaurantItem $item, int $qtyValidated, $auth)
    {
        $prepStatus = $item->statuses()
            ->where('status', OrderMenuRestaurantItemStatus::IN_PREPARATION->value)
            ->first();

        if (!$prepStatus || $prepStatus->quantity <= 0) return;

        $qtyToProcess = min($qtyValidated, $prepStatus->quantity);

        // 1. Déduire de la préparation
        $prepStatus->decrement('quantity', $qtyToProcess);
        $isNowFinished = ($prepStatus->fresh()->quantity <= 0);

        // 2. Gérer la transition PARTIAL -> TOTAL
        if ($isNowFinished) {
            // On cherche s'il y avait déjà une livraison partielle pour la convertir
            $deliveryStatus = $item->statuses()
                ->where('status', OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value)
                ->first();

            if ($deliveryStatus) {
                // On transforme le PARTIAL existant en TOTAL
                $deliveryStatus->update([
                    'status' => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                    'quantity' => $deliveryStatus->quantity + $qtyToProcess
                ]);
            } else {
                // Sinon on crée directement le TOTAL
                $item->statuses()->create([
                    'status' => OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                    'quantity' => $qtyToProcess,
                    'created_by' => $auth->id
                ]);
            }
        } else {
            // Toujours en partiel : on crée ou on incrémente
            $partial = $item->statuses()->firstOrCreate(
                ['status' => OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value],
                ['quantity' => 0, 'created_by' => $auth->id]
            );
            $partial->increment('quantity', $qtyToProcess);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::validateMenusForOrder
     * @permission_desc Mettre en prêt les plats d'une commande
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
                $remainingQty = max(0, $item->quantity_exactly - $item->quantity_delivered);

                Log::info($remainingQty);

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

                $this->updateItemStatusFromPreparation($item, $qtyToDeliver, $auth);

                // 🔹 Mettre à jour l'item
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
                    'virtuals' => $virtualLogs,
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


    private function updateItemStatusToFinalDelivery(OrderMenuRestaurantItem $item, int $qtyValidated, $auth)
    {
        // 🔹 Récupérer le status à utiliser : TOTAL_DELIVERED si existe, sinon PARTIAL_DELIVERED
        $status = $item->statuses()
            ->whereIn('status', [
                OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
            ])
            ->orderByRaw("FIELD(status, '".OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value."', '".OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value."')")
            ->first();

        if (!$status || $status->quantity <= 0) return;

        $qtyToProcess = min($qtyValidated, $status->quantity);

        // 🔻 Décrémenter le status précédent
        $status->decrement('quantity', $qtyToProcess);

        // 🔹 Créer un enregistrement DELIVERED avec la quantité validée
        $item->statuses()->create([
            'status' => OrderMenuRestaurantItemStatus::DELIVERED->value,
            'quantity' => $qtyToProcess,
            'created_by' => $auth->id
        ]);
    }

    private function rejectReadyItems(OrderMenuRestaurantItem $item, int $qtyToReject, $auth)
    {
        // 1. On cherche les quantités "prêtes" (Total ou Partiel)
        $status = $item->statuses()
            ->whereIn('status', [
                OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value,
                OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value
            ])
            ->orderByRaw("FIELD(status, '".OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value."', '".OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value."')")
            ->first();

        if (!$status || $status->quantity <= 0) return;

        $qtyToProcess = min($qtyToReject, $status->quantity);

        // 2. Décrémenter le stock prêt précédent
        $status->decrement('quantity', $qtyToProcess);

        if ($status->fresh()->quantity <= 0) {
            $status->delete();
        }

        $rejectedStatus = $item->statuses()->firstOrCreate(
            ['status' => OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value],
            [
                'quantity' => 0,
                'created_by' => $auth->id
            ]
        );
        $rejectedStatus->increment('quantity', $qtyToProcess);
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

            $warehouse = Warehouse::where('is_used_for_restaurant', true)->firstOrFail();
            $stockLogs = [];

            foreach ($request->items as $pItem) {
                $item = $order->items->firstWhere('uuid', $pItem['item_uuid']);
                if (!$item) continue;

                $qtyToDeliver = (int) $pItem['quantity_to_deliver'];

                // 🔹 Mettre à jour les statuses dans la table status
                $this->updateItemStatusToFinalDelivery($item, $qtyToDeliver, $auth);

                // 🔹 Déduction du stock réel pour les virtuals
                foreach ($item->virtuals->where('item_type', 'menu') as $v) {
                    $toDeduct = min($qtyToDeliver, $v->quantity_delivered);

                    if ($toDeduct <= 0) continue;

                    $produitPoint = ProductPoint::where('produit_uuid', $v->product_uuid)->where('point_uuid', $warehouse->uuid)->first();

                    if ($produitPoint) {
                        $stockBefore = $produitPoint->quantity;
                        $produitPoint->quantity = max(0, $produitPoint->quantity - $toDeduct);
                        $produitPoint->save();

                        $v->quantity_delivered -= $toDeduct;
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


                $item->quantity_delivered = max(0, $item->quantity_delivered - $qtyToDeliver);
                $item->status = ($item->quantity <= 0)
                    ? OrderMenuRestaurantItemStatus::DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                $item->save();
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
            $selectedItems = collect($request->items)->pluck('item_uuid')->toArray();

            foreach ($order->drinks as $drink) {

                if (!in_array($drink->uuid, $selectedItems)) {
                    continue;
                }
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

            $order->update([
                'updated_by' => $auth->id,
            ]);

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
     * @permission OrderMenuRestaurantController::cancelMenuValidation
     * @permission_desc Rejetter les plats d'une commande marqué prêt
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
                if (!isset($selectedItems[$item->uuid])) {
                    continue;
                }

                $data = $selectedItems[$item->uuid];
                $reason = $data['reason'];
                $qtyToCancel = (int) $data['quantity_to_deliver'];

                // 1. Mise à jour du dictionnaire des statuts (REJECTED_FOR_NEW_UPDATE)
                // On déplace physiquement la quantité du statut "Prêt" vers "Rejeté"
                $this->rejectReadyItems($item, $qtyToCancel, $auth);

                // 2. Logique de restauration de l'item principal
                // On limite la restauration à la quantité saisie sans dépasser ce qui a été livré
                $actuallyDelivered = (int) $item->quantity_delivered;
                $restoreAmount = min($qtyToCancel, $actuallyDelivered);

                if ($restoreAmount > 0) {
                    $item->quantity += $restoreAmount;
                    $item->quantity_delivered -= $restoreAmount;
                    $item->quantity_final_used -= $restoreAmount;
                }

                // ⚡ Passage en mode rejeté pour modification
                $item->status = OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;
                $item->reason = $reason;
                $item->is_rejected = true;
                $item->save();

                // 3. Logique des virtuals (ingrédients)
                $virtuals = $item->virtuals->where('item_type', 'menu');
                foreach ($virtuals as $v) {
                    // On restaure au prorata de ce qui a été annulé
                    $vQtyDelivered = (int) $v->quantity_delivered;
                    $qtyToRestoreVirtual = min($qtyToCancel, $vQtyDelivered);

                    if ($qtyToRestoreVirtual <= 0) continue;

                    // Restaurer le stock physique si DELIVERED
                    if ($v->status === OrderMenuRestaurantItemStatus::DELIVERED->value) {
                        $produitPoint = ProductPoint::where('produit_uuid', $v->product_uuid)
                            ->where('point_uuid', $warehouse->uuid)
                            ->first();
                        if ($produitPoint) {
                            $produitPoint->increment('quantity', $qtyToRestoreVirtual);
                        }
                    }

                    $v->quantity_reserved += $qtyToRestoreVirtual;
                    $v->quantity_delivered -= $qtyToRestoreVirtual;
                    $v->status = OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;
                    $v->save();

                    $restorationLogs[] = [
                        'product' => $v->product_uuid,
                        'restored_qty' => $qtyToRestoreVirtual,
                        'warehouse_restored' => $v->status === OrderMenuRestaurantItemStatus::DELIVERED->value,
                        'reason' => $reason
                    ];
                }
            }

            // 4. Mise à jour globale de la commande
            $order->update([
                'status' => MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value,
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation effectuée avec succès !',
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
     * @permission OrderMenuRestaurantController::cancelDrinkValidation
     * @permission_desc Rejetter les boissons d'une commande marqué comme prêt
     */
    public function cancelDrinkValidation(Request $request ,string $orderUuid)
    {
        $auth = auth()->user();
        $request->validate([
            'items' => 'required|array',
            'items.*.item_uuid' => 'required|exists:order_restaurannts_drinks,uuid',
            'items.*.reason' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $order = OrderMenuRestaurant::where('uuid', $orderUuid)
                ->with(['drinks'])
                ->firstOrFail();

            $warehouse = Warehouse::where('is_bar_warehouse', true)->firstOrFail();
            $restorationLogs = [];

            $selectedItems = collect($request->items)->keyBy('item_uuid');

            foreach ($order->drinks as $drink) {

                if (!isset($selectedItems[$drink->uuid])) {
                    continue; // on ne touche pas aux drinks non sélectionnés
                }

                $reason = $selectedItems[$drink->uuid]['reason'];
                $toRestore = (int) $drink->quantity_delivered;

                if ($toRestore > 0) {
                    // 🔹 Restauration de la quantité dans l'item
                    $drink->quantity += $toRestore;
                    $drink->quantity_delivered -= $toRestore;
                    $drink->quantity_final_used -= $toRestore;

                    // 🔹 Restauration du stock physique
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
                        'warehouse_restored' => true,
                        'reason' => $reason
                    ];
                }

                // ⚡ Mettre le drink à "Rejetée pour modification" avec raison
                $drink->status = OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value;
                $drink->reason = $reason;
                $drink->save();
            }

            // ⚡ Mettre la commande à "Rejetée pour modification"
            $order->status = MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value;
            $order->save();

            $order->update([
                'updated_by' => $auth->id,
            ]);

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
                    $order->status = MenuOrderStatus::TRANSFERED->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value:
                    $order->status = MenuOrderStatus::REJECTED_FOR_NEW_UPDATE->value;
                    $order->save();
                    return;

                case OrderMenuRestaurantItemStatus::TOTAL_DELIVERED->value:
                    $order->status = MenuOrderStatus::PARTIAL_COMPLETED->value;
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

                case OrderMenuRestaurantItemStatus::IN_PREPARATION->value:
                    $order->status = MenuOrderStatus::IN_PREPARATION->value;
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

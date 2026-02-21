<?php

namespace App\Http\Controllers;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\InvoiceForMenuOrder;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\MenuRestaurant;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
/**
 * @permission_category Gestion des commandes du restaurant
 * @permission_module Gestion du restaurant
 */
class OrderMenuRestaurantController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::store
     * @permission_desc Créer les commandes du restaurant
     */
    public function store(Request $request)
    {
        $auth = auth()->user();
        \Log::info('Store Order Request:', $request->all());

        DB::beginTransaction();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'type_clients_for_payment' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
                'restaurant_table_uuid' => ['nullable','uuid','required_if:type_clients_for_payment,' . ConsumptionType::DINE_IN->value, 'uuid', 'exists:restaurant_tables,uuid'],
                'order_menu_restaurant_date' => ['required', 'date_format:Y-m-d H:i:s'],
                'consumption_type' => ['required', 'string', new Enum(ConsumptionType::class)],
                'partners_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value, 'exists:restaurant_partners,uuid'],
                'warehouse_uuid' => ['nullable', 'uuid', 'exists:warehouses,uuid'],
                'restaurant_room_uuid' => ['nullable', 'uuid', 'exists:restaurant_rooms,uuid'],
                'menus' => ['required', 'array', 'min:1'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
                'menus.*.unit_price' => ['required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value . ',' . TypeClientsForPaiment::DEBTOR->value, 'nullable', 'numeric', 'min:0',],
                'remise' => ['nullable', 'numeric', 'min:0'],
                'full_name' => ['nullable', 'string', 'max:255', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::DEBTOR->value, Rule::unique('orders_menu_restaurants', 'full_name')->where(function ($query) {$query->where('type_clients_for_payment', TypeClientsForPaiment::DEBTOR->value);}),],

            ],[
                    'type_clients_for_payment.required' => 'Le type de client est obligatoire.',
                    'type_clients_for_payment.enum' => 'Le type de client sélectionné est invalide.',

                    'restaurant_table_uuid.required_if' => 'La table est obligatoire pour une consommation sur place.',
                    'restaurant_table_uuid.uuid' => 'La table sélectionnée est invalide.',
                    'restaurant_table_uuid.exists' => 'La table sélectionnée n’existe pas.',

                    'order_menu_restaurant_date.required' => 'La date de la commande est obligatoire.',
                    'order_menu_restaurant_date.date_format' => 'Le format de la date est invalide (Y-m-d H:i:s).',

                    'consumption_type.required' => 'Le type de consommation est obligatoire.',
                    'consumption_type.enum' => 'Le type de consommation est invalide.',

                    'partners_restaurant_uuid.required_if' => 'Le partenaire est obligatoire pour ce type de client.',
                    'partners_restaurant_uuid.uuid' => 'Le partenaire sélectionné est invalide.',
                    'partners_restaurant_uuid.exists' => 'Le partenaire sélectionné n’existe pas.',

                    'warehouse_uuid.uuid' => 'Le dépôt sélectionné est invalide.',
                    'warehouse_uuid.exists' => 'Le dépôt sélectionné n’existe pas.',

                    'restaurant_room_uuid.required_if' => 'La salle est obligatoire pour une consommation sur place.',
                    'restaurant_room_uuid.uuid' => 'La salle sélectionnée est invalide.',
                    'restaurant_room_uuid.exists' => 'La salle sélectionnée n’existe pas.',

                    'menus.required' => 'Veuillez ajouter au moins un menu à la commande.',
                    'menus.array' => 'Les menus sont invalides.',
                    'menus.min' => 'Veuillez ajouter au moins un menu.',

                    'menus.*.menus_restaurant_uuid.required' => 'Un menu est manquant.',
                    'menus.*.menus_restaurant_uuid.uuid' => 'Le menu sélectionné est invalide.',
                    'menus.*.menus_restaurant_uuid.exists' => 'Le menu sélectionné n’existe pas.',

                    'menus.*.quantity.required' => 'La quantité est obligatoire.',
                    'menus.*.quantity.numeric' => 'La quantité doit être un nombre.',
                    'menus.*.quantity.min' => 'La quantité doit être au moins égale à 1.',

                    'menus.*.unit_price.required_if' => 'Le prix unitaire est obligatoire pour ce type de client.',
                    'menus.*.unit_price.numeric' => 'Le prix unitaire doit être un nombre.',
                    'menus.*.unit_price.min' => 'Le prix unitaire ne peut pas être négatif.',

                    'remise.numeric' => 'La remise doit être un nombre.',
                    'remise.min' => 'La remise ne peut pas être négative.',

                    'full_name.required_if' => 'Le nom du débiteur est obligatoire.',
                    'full_name.unique' => 'Ce débiteur a déjà une commande en cours.',
                    'full_name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
                ]);

            // 🔹 Déterminer l’entrepôt pour la cuisine
            $warehouseUuid = $validated['warehouse_uuid']
                ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            $isFree = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;

            $results = [];

            // 🔹 Étape 1 : Construire la composition des menus
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

                $unitPrice = $menuInput['unit_price'] ?? 0; // Si free ou absent, on met 0 par défaut
                $menuQuantity = $menuInput['quantity'] ?? 0;

                $results[] = [
                    'menu' => [
                        'uuid' => $menuInput['menus_restaurant_uuid'] ?? null,
                        'name' => $menu->name ?? 'Menu inconnu',
                        'quantity_ordered' => $menuQuantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $menuQuantity * $unitPrice,
                    ],
                    'composition' => $composition,
                ];

                // 🔹 Log après chaque menu, même si composition vide
                \Log::info('Menu et composition', [
                    'menu_uuid' => $menuInput['menus_restaurant_uuid'],
                    'menu_name' => $menu->name ?? 'Menu inconnu',
                    'composition_count' => count($composition),
                    'composition' => $composition,
                ]);
            }

            // 🔹 Étape 2 : Vérification des stocks
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


            // 🔹 Étape 3 : Si stock insuffisant, renvoyer erreur
            if (!empty($stockErrors)) {
                $messages = [];
                $menusSeen = [];

                foreach ($stockErrors as $err) {
                    if (!in_array($err['menu_name'], $menusSeen)) {
                        $messages[] = "Impossible de commander le menu {$err['menu_name']} : stock insuffisant.";
                        $menusSeen[] = $err['menu_name'];
                    }
                }

                return response()->json([
                    'status' => 'error',
                    // 👇 on met tous les messages sur la même ligne, séparés par un espace
                    'message' => implode(' ', $messages),
                    'details' => $stockErrors,
                ], 422);
            }



            // 🔹 Étape 4 : Enregistrer la commande principale
            $order = OrderMenuRestaurant::create([
                'status' => 'pending',
                'type_clients_for_payment' => $validated['type_clients_for_payment'],
                'consumption_type' => $validated['consumption_type'],
                'restaurant_table_uuid' => $validated['restaurant_table_uuid'] ?? null,
                'warehouse_uuid' => $warehouseUuid,
                'partners_restaurant_uuid' => $validated['partners_restaurant_uuid'] ?? null,
                'restaurant_room_uuid' => $validated['restaurant_room_uuid'] ?? null,
                'order_menu_restaurant_date' => $validated['order_menu_restaurant_date'],
                'remise' => $validated['remise'],
                'full_name' => $validated['full_name'] ?? null,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);

            // 🔹 Étape 5 : Enregistrer les menus commandés et virtual reserve
            foreach ($results as $menuResult) {
                $menuQuantity = $menuResult['menu']['quantity_ordered'];
                $isFreeMenu = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;

                // Enregistrer le menu commandé
                $menuItem = OrderMenuRestaurantItem::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'menus_restaurant_uuid' => $menuResult['menu']['uuid'],
                    'quantity' => $menuQuantity,
                    'quantity_exactly' => $menuQuantity,
                    'unit_price' => $menuResult['menu']['unit_price'],
                    'total_price' => $menuResult['menu']['total_price'],
                    'is_free' => $isFreeMenu,
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);

                // Enregistrer les produits virtuels du menu
                foreach ($menuResult['composition'] as $product) {
                    $quantityReserved = $menuQuantity * $product['quantity_per_menu'];

                    // Enregistrement dans la table virtuelle
                    VirtualOrderMenuRestaurant::create([
                        'orders_menu_restaurant_uuid' => $order->uuid,
                        'item_uuid' => $menuItem->uuid,
                        'product_uuid' => $product['product_uuid'],
                        'quantity_reserved' => $quantityReserved,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]);
                }
            }

            $cuisinierRole = Role::where('name', 'CUISINIER')->firstOrFail();

            // On prend le premier utilisateur avec ce rôle disponible
            $recipient = $cuisinierRole->users()->firstOrFail();

            // Mettre à jour la commande
            $order->update([
                'status' => MenuOrderStatus::TRANSFERED->value, // commande transférée
                'received_by' => $recipient->id,
                'transfered_at' => now(),
                'transfered_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);

            \Log::info("Commande {$order->uuid} transférée automatiquement à l'utilisateur CUISINIER {$recipient->id}");

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Commande enregistrée avec succès',
                'order_uuid' => $order->uuid,
                'menus' => $results,
                'menuItem' => $menuItem,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            \Log::error('Validation exception', $e->errors());
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Exception in store order', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de l’enregistrement de la commande.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::update
     * @permission_desc Modifier les commandes du restaurant
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();
        \Log::info('Update Order Request:', $request->all());

        DB::beginTransaction();

        try {
            // 🔹 Récupérer la commande existante
            $order = OrderMenuRestaurant::with('items')->where('uuid', $uuid)->firstOrFail();

            // 🔹 Validation (même que store)
            $validated = $request->validate([
                'type_clients_for_payment' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
                'restaurant_table_uuid' => ['nullable','uuid','required_if:type_clients_for_payment,' . ConsumptionType::DINE_IN->value, 'uuid', 'exists:restaurant_tables,uuid'],
                'order_menu_restaurant_date' => ['required', 'date_format:Y-m-d H:i:s'],
                'consumption_type' => ['required', 'string', new Enum(ConsumptionType::class)],
                'partners_restaurant_uuid' => ['nullable', 'uuid', 'required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value, 'exists:restaurant_partners,uuid'],
                'warehouse_uuid' => ['nullable', 'uuid', 'exists:warehouses,uuid'],
                'restaurant_room_uuid' => ['nullable', 'uuid', 'exists:restaurant_rooms,uuid'],
                'menus' => ['required', 'array', 'min:1'],
                'menus.*.menus_restaurant_uuid' => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
                'menus.*.quantity' => ['required', 'numeric', 'min:1'],
                'menus.*.unit_price' => ['required_if:type_clients_for_payment,' . TypeClientsForPaiment::PARTNER->value . ',' . TypeClientsForPaiment::DEBTOR->value, 'nullable', 'numeric', 'min:0',],
                'remise' => ['nullable', 'numeric', 'min:0'],
                'full_name' => [
                    'nullable',
                    'string',
                    'max:255',
                    'required_if:type_clients_for_payment,' . TypeClientsForPaiment::DEBTOR->value,
                    Rule::unique('orders_menu_restaurants', 'full_name')
                        ->where(function ($query) {
                            $query->where('type_clients_for_payment', TypeClientsForPaiment::DEBTOR->value);
                        })
                        ->ignore($order->uuid, 'uuid'),
                ],
            ]);

            $warehouseUuid = $validated['warehouse_uuid']
                ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            $isFree = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;

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

                $unitPrice = $menuInput['unit_price'] ?? 0; // Si free ou absent, on met 0 par défaut
                $menuQuantity = $menuInput['quantity'] ?? 0;

                $results[] = [
                    'menu' => [
                        'uuid' => $menuInput['menus_restaurant_uuid'] ?? null,
                        'name' => $menu->name ?? 'Menu inconnu',
                        'quantity_ordered' => $menuQuantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $menuQuantity * $unitPrice,
                    ],
                    'composition' => $composition,
                ];

                // 🔹 Log après chaque menu, même si composition vide
                \Log::info('Menu et composition', [
                    'menu_uuid' => $menuInput['menus_restaurant_uuid'],
                    'menu_name' => $menu->name ?? 'Menu inconnu',
                    'composition_count' => count($composition),
                    'composition' => $composition,
                ]);
            }

            // 🔹 Vérification du stock
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

            if (!empty($stockErrors)) {
                $messages = [];
                $menusSeen = [];

                foreach ($stockErrors as $err) {
                    if (!in_array($err['menu_name'], $menusSeen)) {
                        $messages[] = "Impossible de commander le menu {$err['menu_name']} : stock insuffisant.";
                        $menusSeen[] = $err['menu_name'];
                    }
                }

                return response()->json([
                    'status' => 'error',
                    'message' => implode(' ', $messages),
                    'details' => $stockErrors,
                ], 422);
            }

            // 🔹 Mettre à jour la commande principale
            $order->update([
                'status' => 'pending',
                'type_clients_for_payment' => $validated['type_clients_for_payment'],
                'consumption_type' => $validated['consumption_type'],
                'restaurant_table_uuid' => $validated['restaurant_table_uuid'] ?? null,
                'warehouse_uuid' => $warehouseUuid,
                'partners_restaurant_uuid' => $validated['partners_restaurant_uuid'] ?? null,
                'restaurant_room_uuid' => $validated['restaurant_room_uuid'] ?? null,
                'order_menu_restaurant_date' => $validated['order_menu_restaurant_date'],
                'remise' => $validated['remise'],
                'full_name' => $validated['full_name'] ?? null,
                'updated_by' => $auth->id,
            ]);

            // 🔹 Supprimer les anciens items et réservations virtuelles
            OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $order->uuid)->delete();
            VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)->delete();

            // 🔹 Ajouter les nouveaux items
            foreach ($results as $menuResult) {
                $isFreeMenu = $validated['type_clients_for_payment'] === TypeClientsForPaiment::FREE->value;
                $menuItem = OrderMenuRestaurantItem::create([
                    'order_menu_restaurant_uuid' => $order->uuid,
                    'menus_restaurant_uuid' => $menuResult['menu']['uuid'],
                    'quantity' => $menuResult['menu']['quantity_ordered'],
                    'quantity_exactly' => $menuResult['menu']['quantity_ordered'],
                    'unit_price' => $menuResult['menu']['unit_price'],
                    'total_price' => $menuResult['menu']['total_price'],
                    'is_free' => $isFreeMenu,
                    'status' => \App\Enums\OrderMenuRestaurantItemStatus::NOT_DELIVERED->value,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);

                // Enregistrer les produits virtuels du menu
                foreach ($menuResult['composition'] as $product) {
                    $quantityReserved = $menuQuantity * $product['quantity_per_menu'];

                    // Enregistrement dans la table virtuelle
                    VirtualOrderMenuRestaurant::create([
                        'orders_menu_restaurant_uuid' => $order->uuid,
                        'item_uuid' => $menuItem->uuid,
                        'product_uuid' => $product['product_uuid'],
                        'quantity_reserved' => $quantityReserved,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ]);

                    // 🔹 Log pour vérifier
//                    \Log::info('Produit virtuel enregistré', [
//                        'order_uuid' => $order->uuid,
//                        'menu_uuid' => $menuResult['menu']['uuid'],
//                        'product_uuid' => $product['product_uuid'],
//                        'product_name' => $product['product_name'],
//                        'menu_quantity' => $menuQuantity,
//                        'quantity_per_menu' => $product['quantity_per_menu'],
//                        'quantity_reserved' => $quantityReserved,
//                    ]);
                }
            }

            $cuisinierRole = Role::where('name', 'CUISINIER')->firstOrFail();
            $recipient = $cuisinierRole->users()->firstOrFail();

            $order->update([
                'status' => MenuOrderStatus::TRANSFERED->value,
                'received_by' => $recipient->id,
                'transfered_at' => now(),
                'transfered_by' => $auth->id,
                'updated_by' => $auth->id,
            ]);

            \Log::info("Commande {$order->uuid} transférée automatiquement à CUISINIER {$recipient->id}");

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Commande mise à jour avec succès',
                'order_uuid' => $order->uuid,
                'menus' => $results,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            \Log::error('Validation exception', $e->errors());
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Exception in update order', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de la commande.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }





    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::index
     * @permission_desc Afficher la liste des commandes du restaurant
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

        if (!$auth->hasRole('SUPER_ADMIN')) {

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

        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_orders_for_restaurant')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
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
                    $messages[] = "Menu « {$err['menu_name']} » : produit « {$err['product_name']} » insuffisant (en stock : {$err['quantity_in_stock']})";
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



    /**
     * Display a listing of the resource.
     * @permission OrderMenuRestaurantController::show
     * @permission_desc Afficher les détails d'une commandes du restaurant
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
                'items.menu',
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
     * @permission_desc Transférer une commande du restaurant
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
     * @permission_desc Rejetter une commande du restaurant
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
     * @permission OrderMenuRestaurantController::CancelOrderMenuRestaurant
     * @permission_desc Annuler une commande du restaurant
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
     * @permission_desc Annuler une commande du restaurant par le SUPER ADMIN
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
     * @permission OrderMenuRestaurantController::validateItemForOrderMenuRestaurant
     * @permission_desc Marquer un ou plusieurs menus d'une commande comme livrés
     */
    public function validateItemForOrderMenuRestaurant(Request $request, string $uuid)
    {
        $request->validate([
            '*.item_uuid' => ['required', 'uuid'],
            '*.quantity_to_deliver' => ['required', 'integer', 'min:1'],
        ]);

        DB::beginTransaction();
        try {
            // Charger la commande avec TOUS ses items pour vérifier l'état global à la fin
            $order = OrderMenuRestaurant::where('uuid', $uuid)
                ->with('items')
                ->firstOrFail();

            $payloadItems = collect($request->all());
            $itemsRemainingLogs = [];
            $virtualLogs = [];

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ TRAITEMENT DES ITEMS PRINCIPAUX
            |--------------------------------------------------------------------------
            */
            foreach ($payloadItems as $pItem) {
                $item = $order->items->firstWhere('uuid', $pItem['item_uuid']);
                if (!$item) continue;

                $remainingQty = $item->quantity; // Dans ton schéma, quantity semble être le RESTE à livrer

                if ($remainingQty <= 0) {
                    throw new \Exception("L'item {$item->menu->name} est déjà totalement livré.");
                }

                if ($pItem['quantity_to_deliver'] > $remainingQty) {
                    throw new \Exception("Quantité demandée ({$pItem['quantity_to_deliver']}) supérieure au restant ({$remainingQty}) pour {$item->menu->name}");
                }

                $deliveredThisRound = $pItem['quantity_to_deliver'];

                // Calcul de la proportion AVANT de modifier l'item
                // Proportion = (ce qu'on livre maintenant) / (ce qu'il restait à livrer)
                $proportion = $deliveredThisRound / $remainingQty;

                // Mise à jour de l'item principal
                $item->has_been_validated = true;
                $item->quantity_delivered += $deliveredThisRound;
                $item->quantity -= $deliveredThisRound;
                $item->status = ($item->quantity <= 0)
                    ? OrderMenuRestaurantItemStatus::DELIVERED->value
                    : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;
                $item->save();

                /*
                |--------------------------------------------------------------------------
                | 2️⃣ TRAITEMENT DES VIRTUALS (STOCKS) LIÉS A CET ITEM
                |--------------------------------------------------------------------------
                */
                $virtuals = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $order->uuid)
                    ->where('item_uuid', $item->uuid)
                    ->get();

                foreach ($virtuals as $v) {
                    // On applique la proportion sur ce qu'il RESTE dans le virtual
                    $toDeliverFromVirtual = (int) round($v->quantity_reserved * $proportion);

                    // Sécurité : ne pas livrer plus que ce qui est réservé
                    $toDeliverFromVirtual = min($toDeliverFromVirtual, $v->quantity_reserved);

                    $v->quantity_reserved -= $toDeliverFromVirtual;
                    // Si tu as une colonne quantity_delivered dans virtuals, incrémente-la ici
                    // $v->quantity_delivered += $toDeliverFromVirtual;

                    $v->status = ($v->quantity_reserved <= 0)
                        ? OrderMenuRestaurantItemStatus::DELIVERED->value
                        : OrderMenuRestaurantItemStatus::PARTIAL_DELIVERED->value;

                    $v->save();

                    $virtualLogs[] = [
                        'product_uuid' => $v->product_uuid,
                        'delivered' => $toDeliverFromVirtual,
                    ];
                }

                $itemsRemainingLogs[] = [
                    'item_uuid' => $item->uuid,
                    'delivered_this_round' => $deliveredThisRound,
                    'remaining_quantity' => $item->quantity,
                    'status' => $item->status,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ DÉDUCTION DES STOCKS (Basé sur les virtuals calculés)
            |--------------------------------------------------------------------------
            */
            $warehouse = Warehouse::where('is_used_for_restaurant', true)->first();
            $deductions = [];

            foreach ($virtualLogs as $log) {
                if ($log['delivered'] <= 0) continue;

                $produitPoint = ProductPoint::where('produit_uuid', $log['product_uuid'])
                    ->where('point_uuid', $warehouse->uuid)
                    ->first();

                if ($produitPoint) {
                    $produitPoint->quantity -= $log['delivered'];
                    if ($produitPoint->quantity < 0) $produitPoint->quantity = 0;
                    $produitPoint->save();

                    $deductions[] = [
                        'product_uuid' => $log['product_uuid'],
                        'deducted' => $log['delivered']
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ STATUT DE LA COMMANDE (Basé sur TOUS les items)
            |--------------------------------------------------------------------------
            */
            $order->refresh(); // Recharger les relations pour avoir les status à jour
            $allItemStatus = $order->items->pluck('status')->toArray();

            $isAllDelivered = collect($allItemStatus)->every(fn($s) => $s === OrderMenuRestaurantItemStatus::DELIVERED->value);
            $isAnyDelivered = collect($allItemStatus)->contains(fn($s) => $s !== OrderMenuRestaurantItemStatus::NOT_DELIVERED->value);

            $order->status = $isAllDelivered
                ? MenuOrderStatus::COMPLETED->value
                : ($isAnyDelivered ? MenuOrderStatus::PARTIAL_COMPLETED->value : MenuOrderStatus::PENDING->value);

            $order->save();

            DB::commit();
            return response()->json([
                'success' => true,
                'order_status' => $order->status,
                'items' => $itemsRemainingLogs,
                'stock_deductions' => $deductions
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
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

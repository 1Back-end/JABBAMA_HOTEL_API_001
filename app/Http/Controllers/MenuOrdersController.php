<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\MenuRestaurant;
use App\Models\ProductPoint;
use App\Models\Supply;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Enum;

/**
 * @permission_category Composition des menus du restaurant
 * @permission_module Gestion du restaurant
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
     * @permission MenuOrdersController::storeOrUpdateMenu
     * @permission_desc Effectuer la composition des plats des menus du restaurant
     */
    public function storeOrUpdateMenu(Request $request, string $menus_restaurant_uuid)
    {
        $auth = auth()->user();

        // 🔹 Vérification du mot de passe avant tout
        $request->validate([
            'password' => 'required|string'
        ], [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.string'   => 'Le mot de passe doit être une chaîne de caractères.'
        ]);

        // 🔹 Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        // 🔹 Vérifier que le menu existe
        $menu_restaurant = MenuRestaurant::where('uuid', $menus_restaurant_uuid)->first();
        if (!$menu_restaurant) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Menu restaurant non trouvé.'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'warehouse_uuid'        => 'nullable|exists:warehouses,uuid',
                'items'                 => 'required|array|min:1',
                'items.*.uuid'          => 'nullable|exists:menu_order_items,uuid', // pour mise à jour
                'items.*.product_uuid'  => 'required|exists:produits,uuid',
                'items.*.quantity_used' => 'required|numeric|min:1',
                'description'           => 'nullable|string',
            ]);

            // 🔹 Entrepôt par défaut si non fourni
            $warehouseUuid = $validated['warehouse_uuid'] ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            // 🔹 Vérifier si une composition existe déjà pour ce menu
            $menuOrder = MenuOrder::firstOrCreate(
                ['menus_restaurant_uuid' => $menus_restaurant_uuid],
                [
                    'warehouse_uuid' => $warehouseUuid,
                    'status'         => \App\Enums\MenuOrderStatus::PENDING->value,
                    'created_by'     => $auth->id,
                    'description' => $validated['description'] ?? 'Confection du menu ' . trim($menu_restaurant->name),
                ]
            );

            // 🔹 Mise à jour du menuOrder si déjà existant
            $menuOrder->update([
                'warehouse_uuid' => $warehouseUuid,
                'description'   => $validated['description'] ?? $menuOrder->description ?? 'Confection du menu ' . trim($menu_restaurant->name),
                'status'        => \App\Enums\MenuOrderStatus::PENDING->value,
                'updated_by'    => $auth->id,
            ]);

            // 🔹 Gestion des items
            $existingItems = $menuOrder->items()->pluck('uuid')->toArray();
            $submittedItemUuids = [];

            foreach ($validated['items'] as $item) {
                if (!empty($item['uuid']) && in_array($item['uuid'], $existingItems)) {
                    // Mise à jour item existant
                    $menuOrderItem = MenuOrderItem::find($item['uuid']);
                    $menuOrderItem->update([
                        'product_uuid'  => $item['product_uuid'],
                        'quantity_used' => $item['quantity_used'],
                        'menus_restaurant_uuid'=> $menus_restaurant_uuid, // 🔹 ajout ici
                        'updated_by'    => $auth->id,
                    ]);
                    $submittedItemUuids[] = $item['uuid'];
                } else {
                    // Création nouveau item
                    $newItem = MenuOrderItem::create([
                        'menu_order_uuid' => $menuOrder->uuid,
                        'product_uuid'    => $item['product_uuid'],
                        'quantity_used'   => $item['quantity_used'],
                        'menus_restaurant_uuid'=> $menus_restaurant_uuid,
                        'created_by'      => $auth->id,
                        'updated_by'      => $auth->id,
                    ]);
                    $submittedItemUuids[] = $newItem->uuid;
                }
            }

            // 🔹 Supprimer les items qui n'ont pas été soumis
            $menuOrder->items()->whereNotIn('uuid', $submittedItemUuids)->delete();

            // 🔹 Mettre à jour le menu comme confectionné
            $menu_restaurant->update(['is_confectioned' => true]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Composition de menu enregistrée avec succès.',
                'data'    => $menuOrder->load('items')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création/mise à jour.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission MenuOrdersController::show
     * @permission_desc Afficher les détails de la composition d'un menu du restaurant
     */
    public function showByMenu(string $menus_restaurant_uuid)
    {
        $menuOrder = MenuOrder::with([
            'menus_restaurant',
            'warehouse',
            'items.product',
            'creator',
            'updater',
        ])->where('menus_restaurant_uuid', $menus_restaurant_uuid)->first();

        if (!$menuOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune confection trouvée pour ce menu.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'menu_orders' => $menuOrder,
            'message' => 'Composition de menu récupérée avec succès.'
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

    public function show(string $uuid)
    {
        try {
            $menu_order = MenuOrder::with([
                'creator',
                'updater',
                'menus_restaurant',
                'warehouse',
                'validator',
                'cancelor',
                'rejector',
                'items.product',
                'bufferItems',

            ])->where('uuid', $uuid)->firstOrFail();

            return response()->json([
                'status'  => 'success',
                'message' => 'Composition du menu récupéré avec succès.',
                'menu_order'    => $menu_order
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Composition du menu introuvable.',
                'details' => $e->getMessage()
            ], 404);
        }


    }





}

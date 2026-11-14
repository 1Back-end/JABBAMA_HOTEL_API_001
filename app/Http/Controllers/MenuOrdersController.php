<?php

namespace App\Http\Controllers;

use App\Enums\MenuComplementType;
use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\ConfigurationsComplement;
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
 * @permission_category Fiche technique des menus
 * @permission_module Gestion du restaurant
 * @code_module RESTAURANT
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

    public function MenuComplementType()
    {
        return response()->json([
            'status' => 'success',
            'data'   => MenuComplementType::toArray(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission MenuOrdersController::storeOrUpdateMenu
     * @code RESTAURANT
     * @permission_desc Enregistrer la fiche technique d'un menu
     */
    public function storeOrUpdateMenu(Request $request, string $menus_restaurant_uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ], [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.string'   => 'Le mot de passe doit être une chaîne de caractères.'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $menu_restaurant = MenuRestaurant::where('uuid', $menus_restaurant_uuid)->first();
        if (!$menu_restaurant) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Menu restaurant non trouvé.'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'warehouse_uuid'        => 'nullable|exists:warehouses,uuid',
                'items'                 => 'nullable|array',
                'items.*.uuid'          => 'nullable|exists:menu_order_items,uuid',
                'items.*.product_uuid'  => 'required_with:items|exists:produits,uuid',
                'items.*.quantity_used' => 'required_with:items|numeric|min:1',
                'description'           => 'nullable|string',
                'additional_cost'       => 'nullable|numeric|min:0',
            ]);

            $warehouseUuid = $validated['warehouse_uuid'] ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;

            $menuOrder = MenuOrder::firstOrCreate(
                ['menus_restaurant_uuid' => $menus_restaurant_uuid],
                [
                    'warehouse_uuid' => $warehouseUuid,
                    'status'         => \App\Enums\MenuOrderStatus::TRANSFERRED->value,
                    'created_by'     => $auth->id,
                    'description'    => $validated['description'] ?? 'Confection du menu ' . trim($menu_restaurant->name),
                ]
            );

            $menuOrder->update([
                'warehouse_uuid' => $warehouseUuid,
                'description'    => $validated['description'] ?? $menuOrder->description ?? 'Confection du menu ' . trim($menu_restaurant->name),
                'status'         => \App\Enums\MenuOrderStatus::TRANSFERRED->value,
                'updated_by'     => $auth->id,
            ]);

            $existingItems = $menuOrder->items()->pluck('uuid')->toArray();
            $submittedItemUuids = [];

            $totalCalculatedProductionCost = 0;
            $items = $validated['items'] ?? [];

            foreach ($items as $item) {
                $latestSupplyItem = DB::table('supply_items as si')
                    ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                    ->where('si.product_uuid', $item['product_uuid'])
                    ->whereNotNull('si.unit_price')
                    ->whereNull('si.deleted_at')
                    ->whereIn('s.status', [
                        \App\Enums\SupplyStatus::VALIDATED->value,
                        \App\Enums\SupplyStatus::PARTIALLY_VALIDATED->value
                    ])
                    ->orderBy('s.supply_date', 'desc')
                    ->orderBy('s.created_at', 'desc')
                    ->select('si.unit_price')
                    ->first();

                $unitPrice = $latestSupplyItem ? floatval($latestSupplyItem->unit_price) : 0;

                $totalCalculatedProductionCost += ($unitPrice * floatval($item['quantity_used']));

                if (!empty($item['uuid']) && in_array($item['uuid'], $existingItems)) {
                    $menuOrderItem = MenuOrderItem::find($item['uuid']);
                    $menuOrderItem->update([
                        'product_uuid'          => $item['product_uuid'],
                        'quantity_used'         => $item['quantity_used'],
                        'menus_restaurant_uuid' => $menus_restaurant_uuid,
                        'updated_by'            => $auth->id,
                    ]);
                    $submittedItemUuids[] = $item['uuid'];
                } else {
                    $newItem = MenuOrderItem::create([
                        'menu_order_uuid'       => $menuOrder->uuid,
                        'product_uuid'          => $item['product_uuid'],
                        'quantity_used'         => $item['quantity_used'],
                        'menus_restaurant_uuid' => $menus_restaurant_uuid,
                        'created_by'            => $auth->id,
                        'updated_by'            => $auth->id,
                    ]);
                    $submittedItemUuids[] = $newItem->uuid;
                }
            }

            $menuOrder->items()->whereNotIn('uuid', $submittedItemUuids)->delete();

            $hasItemsNow = count($submittedItemUuids) > 0;
            $additionalCost = $validated['additional_cost'] ?? $menu_restaurant->additional_cost;


            $menu_restaurant->update([
                'is_confectioned' => $hasItemsNow,
                'additional_cost' => $additionalCost,
                'production_cost' => $totalCalculatedProductionCost + $additionalCost,
            ]);

            if ($menu_restaurant->is_generated_from_complement) {
                ConfigurationsComplement::where('uuid', $menu_restaurant->uuid)
                    ->update([
                        'is_confectioned' => $hasItemsNow,
                        'additional_cost' => $additionalCost,
                        'production_cost' => $totalCalculatedProductionCost,
                        'updated_by'      => $auth->id,
                    ]);
            }

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
     * @code RESTAURANT
     * @permission_desc Afficher les détails de la fiche technique d'un menu
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


    public function getMultipleLatestPrices(Request $request)
    {
        $request->validate([
            'product_uuids' => 'required|array',
            'product_uuids.*' => 'exists:produits,uuid',
        ]);

        $prices = [];

        foreach ($request->product_uuids as $productUuid) {$latestSupplyItem = DB::table('supply_items as si')
            ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
            ->where('si.product_uuid', $productUuid)
            ->whereNotNull('si.unit_price')
            ->whereNull('si.deleted_at')
            ->whereIn('s.status', [
                \App\Enums\SupplyStatus::VALIDATED->value,
                \App\Enums\SupplyStatus::PARTIALLY_VALIDATED->value
            ])
            ->orderBy('s.supply_date', 'desc')
            ->orderBy('s.created_at', 'desc')
            ->select('si.unit_price', 's.supply_date')
            ->first();

            $prices[$productUuid] = [
                'unit_price'  => $latestSupplyItem ? floatval($latestSupplyItem->unit_price) : 0,
                'supply_date' => $latestSupplyItem ? $latestSupplyItem->supply_date : null,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data'   => $prices
        ]);
    }





}

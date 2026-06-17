<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ComplementComposition;
use App\Models\ComplementCompositionItem;
use App\Models\ConfigurationsComplement;
use App\Models\MenuCategory;
use App\Models\MenuOrder;
use App\Models\MenuOrderItem;
use App\Models\MenuRestaurant;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @permission_category Compléments des menus
 * @permission_module Gestion du restaurant
 */
class ConfigurationsComplementController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::index
     * @permission_desc Afficher la liste des compléments des menus
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = ConfigurationsComplement::with([
            'creator',
            'updater',
        ]);

        if ($request->has('is_active')) {
            $isActive = $request->input('is_active') === 'true' ? true : false;
            $query->where('is_active', $isActive);
        }

        if ($request->has('is_confectioned')) {
            $isConfectioned = $request->input('is_confectioned') === 'true' ? true : false;
            $query->where('is_confectioned', $isConfectioned);
        }

        if ($request->has('is_sellable_directly')) {
            $isSellingDirectly = $request->input('is_sellable_directly') === 'true' ? true : false;
            $query->where('is_sellable_directly', $isSellingDirectly);
        }

        if ($request->filled('menus_restaurant_uuid')) {
            $query->where('menus_restaurant_uuid', $request->menus_restaurant_uuid);
        }
        if ($request->filled('menus_complement_type')) {
            $query->where('menus_complement_type', $request->menus_complement_type);
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('menus_complement_type', 'like', "%{$search}%");
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
     * @permission ConfigurationsComplementController::store
     * @permission_desc Créer un complément
     */
    public function store(Request $request)
    {
        try {

            $auth = auth()->user();

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],

                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],

                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'default_price' => ['nullable', 'numeric', 'min:0'],
                'menus_complement_type' => ['nullable', 'string'],
            ]);

            // =========================
            // CHECK EXISTING COMPLEMENT
            // =========================
            $existingConfig = ConfigurationsComplement::where('name', $validated['name'])->first();

            if ($existingConfig) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce complément existe déjà.'
                ], 400);
            }

            // =========================
            // CLEAN PRICES
            // =========================
            $cleanPrices = function ($arr, $default) {
                $arr = array_filter($arr ?? [], fn($v) => $v !== null);

                $arr = array_map(fn($v) => (float) $v, $arr);

                $arr = array_values(array_unique($arr));

                return count($arr) ? $arr : [(float) $default];
            };

            $validated['prices_for_clients_debtor'] =
                $cleanPrices($validated['prices_for_clients_debtor'] ?? null, $validated['default_price'] ?? 0);

            $validated['prices_for_clients_partner'] =
                $cleanPrices($validated['prices_for_clients_partner'] ?? null, $validated['default_price'] ?? 0);

            $validated['prices_for_clients_free'] =
                $cleanPrices($validated['prices_for_clients_free'] ?? null, $validated['default_price'] ?? 0);

            $validated['created_by'] = $auth->id;

            $config = ConfigurationsComplement::create($validated);

            $category = MenuCategory::first();
            MenuRestaurant::updateOrCreate(
                [
                    'uuid' => $config->uuid,
                ],
                [
                    'name' => $config->name,
                    'description' => $config->description,
                    'created_by' => $auth->id,
                    'category_uuid' => $category?->uuid,
                    'unit_price' => $config->prices_for_clients_debtor,
                    'special_price' => $config->prices_for_clients_partner,
                    'is_active' => true,
                    'is_confectioned' => false,
                    'is_generated_from_complement' => true,
                    'have_complements' => false,
                    'have_drinks' => false,
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Complément et menu créés avec succès',
                'data' => $config
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::show
     * @permission_desc Afficher les détails d'un complément
     */
    public function show(string $uuid)
    {
        try {

            $config = ConfigurationsComplement::with([
                'creator',
                'updater'
            ])->where('uuid', $uuid)->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complément introuvable'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $config
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::updateStatus
     * @permission_desc Activer/Désactiver un complément
     */
    public function updateStatus(Request $request, string $uuid)
    {
        try {

            $auth = auth()->user();

            $config = ConfigurationsComplement::where('uuid', $uuid)->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complément introuvable'
                ], 404);
            }

            $validated = $request->validate([
                'is_active' => ['required', 'boolean'],
            ]);

            $config->update([
                'is_active' => $validated['is_active'],
                'updated_by' => $auth?->id,
            ]);


            if ($config->is_menu_and_complement) {

                $menu = MenuRestaurant::where('uuid', $config->uuid)->first();

                if ($menu) {
                    $menu->update([
                        'is_active' => $validated['is_active'],
                        'updated_by' => $auth?->id,
                    ]);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => $validated['is_active']
                    ? 'Complément activé avec succès'
                    : 'Complément désactivé avec succès',
                'data' => $config->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::update
     * @permission_desc Modifier un complément
     */
    public function update(Request $request, $uuid)
    {
        try {

            $auth = auth()->user();

            $config = ConfigurationsComplement::where('uuid', $uuid)->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complément introuvable'
                ], 404);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],

                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],

                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'default_price' => ['nullable', 'numeric', 'min:0'],
                'menus_complement_type' => ['nullable', 'string'],
            ]);


            $exists = ConfigurationsComplement::where('name', $validated['name'])
                ->where('uuid', '!=', $uuid)
                ->first();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce nom existe déjà'
                ], 400);
            }

            $cleanPrices = function ($arr, $default) {
                $arr = array_filter($arr ?? [], fn($v) => $v !== null);
                $arr = array_map(fn($v) => (float) $v, $arr);
                $arr = array_values(array_unique($arr));

                return count($arr) ? $arr : [(float) $default];
            };

            $validated['prices_for_clients_debtor'] =
                $cleanPrices($validated['prices_for_clients_debtor'] ?? null, $validated['default_price'] ?? 0);

            $validated['prices_for_clients_partner'] =
                $cleanPrices($validated['prices_for_clients_partner'] ?? null, $validated['default_price'] ?? 0);

            $validated['prices_for_clients_free'] =
                $cleanPrices($validated['prices_for_clients_free'] ?? null, $validated['default_price'] ?? 0);

            $validated['updated_by'] = $auth->id;

            $config->update($validated);

            MenuRestaurant::updateOrCreate(
                [
                    'uuid' => $config->uuid,
                ],
                [
                    'name' => $config->name,
                    'description' => $config->description,
                    'created_by' => $config->created_by,
                    'updated_by' => $auth->id,

                    'category_uuid' => MenuCategory::first()?->uuid,

                    'unit_price' => $config->prices_for_clients_debtor,
                    'special_price' => $config->prices_for_clients_partner,

                    'is_active' => $config->is_active,
                    'is_confectioned' => false,
                    'is_generated_from_complement' => true,
                    'have_complements' => false,
                    'have_drinks' => false,
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Complément mis à jour avec succès',
                'data' => $config
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur serveur',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getCompositionByComplementUuid(string $commplements_restaurant_uuid)
    {
        $composition = ComplementComposition::with([
            'complement',
            'items.product',
            'creator',
            'updater',
            'warehouse'
        ])
            ->where('commplements_restaurant_uuid', $commplements_restaurant_uuid)
            ->first();

        if (!$composition) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune composition trouvée pour ce complément.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'composition' => $composition,
            'message' => 'Composition du complément récupérée avec succès.'
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::upsert
     * @permission_desc Effectuer la confection des compléments
     */
    public function upsert(Request $request, string $commplements_restaurant_uuid)
    {
        $request->validate([
            'warehouse_uuid' => 'required|uuid',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|uuid',
            'items.*.quantity_used' => 'required|numeric|min:0',
            'items.*.is_optional' => 'nullable|boolean',
        ]);

        $auth = auth()->user();

        DB::beginTransaction();

        try {

            $composition = ComplementComposition::updateOrCreate(
                [
                    'commplements_restaurant_uuid' => $commplements_restaurant_uuid,
                    'warehouse_uuid' => $request->warehouse_uuid,
                ],
                [
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]
            );


            $complement = ConfigurationsComplement::where('uuid', $commplements_restaurant_uuid)->firstOrFail();

            $complement->update([
                'is_confectioned' => true,
                'updated_by' => $auth->id,
            ]);

            ComplementCompositionItem::where('complement_uuid', $composition->uuid)->delete();

            foreach ($request->items as $item) {
                ComplementCompositionItem::create([
                    'complement_uuid' => $composition->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_used' => $item['quantity_used'],
                    'is_optional' => $item['is_optional'] ?? false,
                    'created_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);
            }

            if ($complement->is_menu_and_complement) {

                $menu_restaurant = MenuRestaurant::where('uuid', $commplements_restaurant_uuid)->first();

                if ($menu_restaurant) {

                    $warehouseUuid = $request->warehouse_uuid
                        ?? Warehouse::where('is_used_for_restaurant', true)->firstOrFail()->uuid;


                    $menuOrder = MenuOrder::firstOrCreate(
                        ['menus_restaurant_uuid' => $menu_restaurant->uuid],
                        [
                            'warehouse_uuid' => $warehouseUuid,
                            'status' => \App\Enums\MenuOrderStatus::TRANSFERRED->value,
                            'created_by' => $auth->id,
                            'description' => $request->description
                                ?? 'Confection du menu ' . trim($menu_restaurant->name),
                        ]
                    );

                    $menuOrder->update([
                        'warehouse_uuid' => $warehouseUuid,
                        'description' => $request->description
                            ?? $menuOrder->description
                                ?? 'Confection du menu ' . trim($menu_restaurant->name),
                        'status' => \App\Enums\MenuOrderStatus::TRANSFERRED->value,
                        'updated_by' => $auth->id,
                    ]);

                    $existingItems = $menuOrder->items()->pluck('uuid')->toArray();
                    $submittedItemUuids = [];

                    foreach ($request->items as $item) {

                        if (!empty($item['uuid']) && in_array($item['uuid'], $existingItems)) {

                            $menuOrderItem = MenuOrderItem::find($item['uuid']);

                            $menuOrderItem?->update([
                                'product_uuid' => $item['product_uuid'],
                                'quantity_used' => $item['quantity_used'],
                                'menus_restaurant_uuid' => $menu_restaurant->uuid,
                                'updated_by' => $auth->id,
                            ]);

                            $submittedItemUuids[] = $item['uuid'];

                        } else {

                            $newItem = MenuOrderItem::create([
                                'menu_order_uuid' => $menuOrder->uuid,
                                'product_uuid' => $item['product_uuid'],
                                'quantity_used' => $item['quantity_used'],
                                'menus_restaurant_uuid' => $menu_restaurant->uuid,
                                'created_by' => $auth->id,
                                'updated_by' => $auth->id,
                            ]);

                            $submittedItemUuids[] = $newItem->uuid;
                        }
                    }

                    $menuOrder->items()
                        ->whereNotIn('uuid', $submittedItemUuids)
                        ->delete();

                    $menu_restaurant->update([
                        'is_confectioned' => true
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Composition enregistrée avec succès.',
                'data' => $composition->load([
                    'warehouse',
                    'complement',
                    'items.product',
                    'creator',
                    'updater',
                ]),
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

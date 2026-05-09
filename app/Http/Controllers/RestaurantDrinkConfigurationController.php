<?php

namespace App\Http\Controllers;

use App\Models\DrinkComposition;
use App\Models\DrinkCompositionItem;
use App\Models\MenuOrder;
use App\Models\RestaurantDrinkConfiguration;
use App\Models\SupplyItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @permission_category Configuration des prix des boissons clients
 * @permission_module Gestion du restaurant
 */
class RestaurantDrinkConfigurationController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::index
     * @permission_desc Afficher la liste des prix des boissons clients
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = RestaurantDrinkConfiguration::with(['creator','updater','product'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('product_uuid', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $config = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $config->items(),
            'current_page' => $config->currentPage(),
            'last_page'    => $config->lastPage(),
            'total'        => $config->total(),
        ]);

    }

    public function get_sall_drinks_for_restaurants(Request $request)
    {
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = RestaurantDrinkConfiguration::with(['creator','updater','product'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('product_uuid', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $config = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $config->items(),
            'current_page' => $config->currentPage(),
            'last_page'    => $config->lastPage(),
            'total'        => $config->total(),
        ]);

    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::transformableProducts
     * @permission_desc Afficher la liste des prix des boissons transformables
     */
    public function transformableProducts(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = RestaurantDrinkConfiguration::with(['creator', 'updater', 'product'])
            ->where('is_transformable_product', true)

            ->when($request->filled('is_active'), function ($query) use ($request) {
                $query->where(
                    'is_active',
                    filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)
                );
            });

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('product_uuid', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
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

    private function getLastSellPrice(string $product_uuid): ?float
    {
        return SupplyItem::where('product_uuid', $product_uuid)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->value('sell_price');
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::store
     * @permission_desc Créer les prix des boissons clients
     */
    public function store(Request $request)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {

            // 🔹 Validation
            $validated = $request->validate([
                'product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'default_price' => ['nullable', 'numeric', 'min:0'],
                'is_finished_product' => ['nullable', 'boolean'],
                'is_transformable_product' => ['nullable', 'boolean'],
            ]);

            // 🔒 Vérifier si déjà configuré
            $existingConfig = RestaurantDrinkConfiguration::where(
                'product_uuid',
                $validated['product_uuid']
            )->first();

            if ($existingConfig) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette boisson a déjà une configuration de prix.'
                ], 400);
            }

            // 🔹 Déterminer le default_price (SÉCURISÉ)
            $defaultPrice = $validated['default_price']
                ?? $this->getLastSellPrice($validated['product_uuid']);

            $validated['default_price'] = $defaultPrice;

            Log::info('RestaurantDrinkConfiguration DEFAULT PRICE', [
                'product_uuid' => $validated['product_uuid'],
                'default_price' => $defaultPrice,
                'source' => $request->has('default_price')
                    ? 'front'
                    : 'approvisionnement'
            ]);

            // 🔁 Injection propre du default_price (sans null, sans doublon)
            $validated['prices_for_clients_debtor'] = array_values(
                array_unique(
                    array_filter(
                        array_merge(
                            [$defaultPrice],
                            $validated['prices_for_clients_debtor'] ?? []
                        ),
                        fn ($v) => $v !== null
                    )
                )
            );

            $validated['prices_for_clients_partner'] = array_values(
                array_unique(
                    array_filter(
                        array_merge(
                            [$defaultPrice],
                            $validated['prices_for_clients_partner'] ?? []
                        ),
                        fn ($v) => $v !== null
                    )
                )
            );

            $validated['prices_for_clients_free'] = array_values(
                array_unique(
                    array_filter(
                        array_merge(
                            [$defaultPrice],
                            $validated['prices_for_clients_free'] ?? []
                        ),
                        fn ($v) => $v !== null
                    )
                )
            );

            // 🔹 Métadonnées
            $validated['created_by'] = $auth->id;
            $validated['has_prices'] = true;

            // 💾 Enregistrement
            $config = RestaurantDrinkConfiguration::create($validated);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Configuration enregistrée avec succès',
                'data' => $config
            ]);

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
                'message' => 'Une erreur est survenue lors de la création.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::update
     * @permission_desc Modifier les prix des boissons clients
     */

    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {

            // 🔹 Configuration existante
            $config = RestaurantDrinkConfiguration::where('uuid', $uuid)->firstOrFail();

            // 🔹 Validation
            $validated = $request->validate([
                'product_uuid' => ['required', 'uuid', 'exists:produits,uuid'],
                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'default_price' => ['nullable', 'numeric', 'min:0'],
                'is_finished_product' => ['nullable', 'boolean'],
                'is_transformable_product' => ['nullable', 'boolean'],
            ]);

            // 🔒 Unicité produit
            $exists = RestaurantDrinkConfiguration::where('product_uuid', $validated['product_uuid'])
                ->where('uuid', '!=', $uuid)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce produit possède déjà une configuration de prix.'
                ], 400);
            }

            // 🔹 Déterminer le default_price (FIABLE)
            $defaultPrice = $validated['default_price']
                ?? $this->getLastSellPrice($validated['product_uuid']);

            $validated['default_price'] = $defaultPrice;

            // 🧾 LOG du prix utilisé
            Log::info('RestaurantDrinkConfiguration DEFAULT PRICE', [
                'product_uuid' => $validated['product_uuid'],
                'default_price' => $defaultPrice,
                'source' => $request->has('default_price')
                    ? 'front'
                    : 'approvisionnement'
            ]);

            // 🔁 Injection PROPRE du default_price
            $validated['prices_for_clients_debtor'] = array_values(
                array_unique(
                    array_filter(
                        array_merge(
                            [$defaultPrice],
                            $validated['prices_for_clients_debtor'] ?? []
                        ),
                        fn ($v) => $v !== null
                    )
                )
            );

            $validated['prices_for_clients_partner'] = array_values(
                array_unique(
                    array_filter(
                        array_merge(
                            [$defaultPrice],
                            $validated['prices_for_clients_partner'] ?? []
                        ),
                        fn ($v) => $v !== null
                    )
                )
            );

            $validated['prices_for_clients_free'] = array_values(
                array_unique(
                    array_filter(
                        array_merge(
                            [$defaultPrice],
                            $validated['prices_for_clients_free'] ?? []
                        ),
                        fn ($v) => $v !== null
                    )
                )
            );

            // 🔹 Métadonnées
            $validated['updated_by'] = $auth->id;
            $validated['has_prices'] = true;

            // 💾 UPDATE
            $config->update($validated);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Configuration mise à jour avec succès',
                'data' => $config->fresh()
            ]);

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
                'message' => 'Une erreur est survenue lors de la mise à jour.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::update_status
     * @permission_desc Activer/Désactiver les prix des boissons clients
     */
    public function update_status(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Valider le statut à mettre à jour
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $config = RestaurantDrinkConfiguration::findOrFail($uuid);

            $config->update([
                'is_active' => $validated['is_active'],
                'updated_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Statut mis à jour avec succès',
                'data' => $config
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du statut : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::show
     * @permission_desc Afficher les détails des prix des boissons clients
     */
    public function show(string $uuid)
    {
        try {
            // 🔹 Récupérer la configuration par UUID
            $config = RestaurantDrinkConfiguration::with(['creator','updater','product'])->findOrFail($uuid);

            return response()->json([
                'status' => 'success',
                'config' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Configuration introuvable : ' . $e->getMessage()
            ], 404);
        }
    }

    public function getTransformableProductByUuid($uuid)
    {
        $data = RestaurantDrinkConfiguration::with(['creator','updater','product'])
            ->where('is_transformable_product', true)
            ->where('uuid', $uuid)
            ->first(); // ✅ IMPORTANT

        if (!$data) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::upsert
     * @permission_desc Effectuer la confection des boissons de transformation
     */
    public function upsert(Request $request, $drinks_restaurant_uuid)
    {
        $request->validate([
            'warehouse_uuid' => 'required|uuid',
            'items' => 'required|array',
            'items.*.product_uuid' => 'required|uuid',
            'items.*.quantity_used' => 'required|numeric|min:0',
        ]);

        $userId = auth()->id();

        DB::beginTransaction();

        try {

            $composition = DrinkComposition::updateOrCreate(
                [
                    'drinks_restaurant_uuid' => $drinks_restaurant_uuid,
                    'warehouse_uuid' => $request->warehouse_uuid,
                ],
                [
                    'updated_by' => $userId,
                    'created_by' => $userId,
                ]
            );

            DrinkCompositionItem::where('drink_composition_uuid', $composition->uuid)->delete();

            foreach ($request->items as $item) {
                DrinkCompositionItem::create([
                    'drink_composition_uuid' => $composition->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_used' => $item['quantity_used'],
                    'is_optional' => $item['is_optional'] ?? false,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Composition enregistrée avec succès',
                'data' => $composition->load(['warehouse', 'items'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function get_compositions_drinks_by_uuid(string $drinks_restaurant_uuid)
    {
        $DrinksOrder = DrinkComposition::with([
            'drink',
            'items.product',
            'creator',
            'updater',
            'warehouse'
        ])->where('drinks_restaurant_uuid', $drinks_restaurant_uuid)->first();

        if (!$DrinksOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune confection trouvée pour cette boisson.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'drinks_orders' => $DrinksOrder,
            'message' => 'Composition de boissons récupérée avec succès.'
        ]);
    }




    /**
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::storeTransformableByName
     * @permission_desc Enregistrer les boissons de transformation
     */
    public function storeTransformableByName(Request $request)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {

            $validated = $request->validate([
                'drink_name' => ['required', 'string', 'max:255'],

                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],

                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'default_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            // 🔒 éviter doublon sur le nom
            $existingConfig = RestaurantDrinkConfiguration::where('drink_name', $validated['drink_name'])
                ->where('is_transformable_product', true)
                ->first();

            if ($existingConfig) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette boisson transformable existe déjà.'
                ], 400);
            }

            // 🔹 default price
            $defaultPrice = $validated['default_price']
                ?? 0;

            $validated['default_price'] = $defaultPrice;

            // 🔁 injection prix sécurisée
            $validated['prices_for_clients_debtor'] = array_values(array_unique(array_filter(array_merge(
                [$defaultPrice],
                $validated['prices_for_clients_debtor'] ?? []
            ))));

            $validated['prices_for_clients_partner'] = array_values(array_unique(array_filter(array_merge(
                [$defaultPrice],
                $validated['prices_for_clients_partner'] ?? []
            ))));

            $validated['prices_for_clients_free'] = array_values(array_unique(array_filter(array_merge(
                [$defaultPrice],
                $validated['prices_for_clients_free'] ?? []
            ))));

            // 🔥 flags métier
            $validated['is_transformable_product'] = true;
            $validated['is_finished_product'] = false;

            $validated['created_by'] = $auth->id;
            $validated['has_prices'] = true;

            $config = RestaurantDrinkConfiguration::create($validated);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Boisson transformable enregistrée avec succès',
                'data' => $config
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
     * Display a listing of the resource.
     * @permission RestaurantDrinkConfigurationController::updateTransformableByName
     * @permission_desc Modifier les boissons de transformation
     */
    public function updateTransformableByName(Request $request, $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {

            $config = RestaurantDrinkConfiguration::where('uuid', $uuid)
                ->where('is_transformable_product', true)
                ->firstOrFail();

            $validated = $request->validate([
                'drink_name' => ['required', 'string', 'max:255'],

                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],

                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'default_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            // 🔹 default price
            $defaultPrice = $validated['default_price']
                ?? $config->default_price
                ?? 0;

            $validated['default_price'] = $defaultPrice;

            // 🔁 injection prix sécurisée
            $validated['prices_for_clients_debtor'] = array_values(array_unique(array_filter(array_merge(
                [$defaultPrice],
                $validated['prices_for_clients_debtor'] ?? []
            ))));

            $validated['prices_for_clients_partner'] = array_values(array_unique(array_filter(array_merge(
                [$defaultPrice],
                $validated['prices_for_clients_partner'] ?? []
            ))));

            $validated['prices_for_clients_free'] = array_values(array_unique(array_filter(array_merge(
                [$defaultPrice],
                $validated['prices_for_clients_free'] ?? []
            ))));

            // 🔥 mise à jour flags
            $validated['is_transformable_product'] = true;
            $validated['is_finished_product'] = false;

            $validated['updated_by'] = $auth->id;

            $config->update($validated);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Boisson transformable mise à jour avec succès',
                'data' => $config->fresh()
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Boisson transformable introuvable.'
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }



}

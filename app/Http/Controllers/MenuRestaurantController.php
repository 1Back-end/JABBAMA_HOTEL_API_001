<?php

namespace App\Http\Controllers;

use App\Enums\MenuTypeComplementBoisson;
use App\Enums\TypeClientsForPaiment;
use App\Models\MenuRestaurant;
use App\Models\MenuRestaurantComplement;
use App\Models\RestaurantDrinkConfiguration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

/**
 * @permission_category Gestion des menus du restaurant
 * @permission_module Gestion du restaurant
 */
class MenuRestaurantController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::index
     * @permission_desc Afficher la liste des menus du restaurant
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('limit', 25);
        $page = (int) $request->input('page', 1);

        $query = MenuRestaurant::with([
            'creator',
            'updater',
            'medias',
            'category',
            'complements'
        ])
            ->where('is_generated_from_complement', false);

        if ($request->has('is_active')) {
            $isActive = $request->input('is_active') === 'true' ? true : false;
            $query->where('is_active', $isActive);
        }

        if ($request->has('is_confectioned')) {
            $isConfectioned = $request->input('is_confectioned') === 'true' ? true : false;
            $query->where('is_confectioned', $isConfectioned);
        }


        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start, $end]);
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // 🔹 Pagination
        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        Log::info($data);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::store
     * @permission_desc Créer les menus du restaurant
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            // Décodage des tableaux JSON légitimes
            if ($request->has('unit_price')) {
                $request->merge([
                    'unit_price' => json_decode($request->unit_price, true),
                ]);
            }

            if ($request->has('special_price')) {
                $request->merge([
                    'special_price' => json_decode($request->special_price, true),
                ]);
            }

            if ($request->has('free_price')) {
                $request->merge([
                    'free_price' => json_decode($request->free_price, true),
                ]);
            }

            if ($request->filled('complements')) {
                $request->merge([
                    'complements' => json_decode($request->complements, true),
                ]);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:menus_restaurants,name',
                'category_uuid' => 'required|exists:menu_categories,uuid',
                'image_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',

                'unit_price' => 'required|array|min:1',
                'unit_price.*' => 'required|numeric|min:0',

                'special_price' => 'nullable|array',
                'special_price.*' => 'numeric|min:0',

                'free_price' => 'nullable|array',
                'free_price.*' => 'numeric|min:0',

                'description' => 'nullable|string',

                'type_complement_menu' => 'nullable|string',
                'quantity_for_type_complement_menu' => 'nullable|integer|min:0',

                'type_complement_boisson' => 'nullable|string',
                'quantity_for_type_complement_boisson' => 'nullable|integer|min:0',

                'complements' => 'nullable|array',
                'complements.*' => 'exists:configurations_complements,uuid',
            ]);

            $validated['created_by'] = $auth->id;
            $haveComplements = false;
            $haveDrinks = false;
            if (!empty($validated['complements'])) {
                $complementsTypes = \DB::table('configurations_complements')
                    ->whereIn('uuid', $validated['complements'])
                    ->pluck('menus_complement_type')
                    ->toArray();
                $haveComplements = in_array('complement', $complementsTypes);
                $haveDrinks = in_array('boisson', $complementsTypes);
            }
            $validated['have_complements'] = $haveComplements;
            $validated['have_drinks'] = $haveDrinks;
            $validated['has_complements'] = !empty($validated['complements']);

            $menu = MenuRestaurant::create($validated);

            if (!empty($validated['complements'])) {
                $pivotData = [];
                foreach ($validated['complements'] as $complementUuid) {
                    $pivotData[$complementUuid] = [
                        'uuid' => (string) Str::uuid(),
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ];
                }
                $menu->complements()->attach($pivotData);
            }

            // Traitement de l'image
            if ($request->hasFile('image_file')) {
                $file = $request->file('image_file');
                $path = $file->store('menus_restaurants', 'public');

                $menu->medias()->create([
                    'name' => $file->getClientOriginalName(),
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => basename($path),
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Menu restaurant créé avec succès.',
                'data' => $menu->load('complements'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Erreur création menu', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erreur serveur',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::update
     * @permission_desc Modifier les menus du restaurant
     */
    public function update_menus(Request $request, string $uuid)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {

            $menu = MenuRestaurant::where('uuid', $uuid)->firstOrFail();


            if ($request->has('unit_price')) {
                $request->merge([
                    'unit_price' => json_decode($request->unit_price, true),
                ]);
            }

            if ($request->has('special_price')) {
                $request->merge([
                    'special_price' => json_decode($request->special_price, true),
                ]);
            }

            if ($request->has('free_price')) {
                $request->merge([
                    'free_price' => json_decode($request->free_price, true),
                ]);
            }

            if ($request->filled('complements')) {
                $request->merge([
                    'complements' => json_decode($request->complements, true),
                ]);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:menus_restaurants,name,' . $menu->uuid . ',uuid',
                'category_uuid' => 'required|exists:menu_categories,uuid',
                'image_file'    => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',

                'unit_price'    => 'required|array|min:1',
                'unit_price.*'  => 'required|numeric|min:0',

                'special_price'   => 'nullable|array',
                'special_price.*' => 'numeric|min:0',

                'free_price' => 'nullable|array',
                'free_price.*' => 'numeric|min:0',

                'description'   => 'nullable|string',

                'type_complement_menu'              => 'nullable|string',
                'quantity_for_type_complement_menu' => 'nullable|integer|min:0',

                'type_complement_boisson'              => 'nullable|string',
                'quantity_for_type_complement_boisson' => 'nullable|integer|min:0',

                'complements'   => 'nullable|array',
                'complements.*' => 'exists:configurations_complements,uuid',
            ]);

            $validated['updated_by'] = $auth->id;
            $haveComplements = false;
            $haveDrinks = false;

            if (!empty($validated['complements'])) {
                $complementsTypes = \DB::table('configurations_complements')
                    ->whereIn('uuid', $validated['complements'])
                    ->pluck('menus_complement_type')
                    ->toArray();

                $haveComplements = in_array('complement', $complementsTypes);
                $haveDrinks = in_array('boisson', $complementsTypes);
            }

            $validated['have_complements'] = $haveComplements;
            $validated['have_drinks']      = $haveDrinks;
            $validated['has_complements'] = !empty($validated['complements']);

            $menu->update($validated);

            $pivotData = [];
            if (!empty($validated['complements'])) {
                foreach ($validated['complements'] as $complementUuid) {
                    $pivotData[$complementUuid] = [
                        'uuid'       => (string) \Illuminate\Support\Str::uuid(),
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ];
                }
            }

            $menu->complements()->sync($pivotData);

            if ($request->hasFile('image_file')) {
                if ($menu->medias()->exists()) {
                    $menu->medias()->delete();
                }

                $file = $request->file('image_file');
                $path = $file->store('menus_restaurants', 'public');

                $menu->medias()->create([
                    'name'      => $file->getClientOriginalName(),
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => basename($path),
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Menu restaurant mis à jour avec succès.',
                'data'    => $menu->load('complements')
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Erreur mise à jour menu:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Erreur serveur',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::show
     * @permission_desc Afficher les détails d'un menu du restaurant
     */
    public function show(string $uuid)
    {
        $menu_restaurant = MenuRestaurant::with(["creator","updater","category","complements"])->where("uuid", $uuid)->firstOrFail();
        if (!$menu_restaurant) {
            return response()->json([
                'status' => 'error',
                'message' => 'Menu du restaurant introuvable.',
                ''
            ], 404);
        }
        return response()->json([
            'status' => 'success',
            'menu_restaurant' => $menu_restaurant,
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::updateStatus
     * @permission_desc Activer/Désactiver les menus du restaurant
     */

    public function updateStatus(string $uuid)
    {
        $auth = auth()->user();

        // Récupérer le menu
        $menu = MenuRestaurant::where('uuid', $uuid)->firstOrFail();

        // Inverser le statut actif
        $menu->is_active = ! $menu->is_active;
        $menu->updated_by = $auth->id;
        $menu->save();

        return response()->json([
            'message'   => 'Statut du menu mis à jour avec succès.',
            'is_active' => $menu->is_active
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::get_menu_is_confectioned
     * @permission_desc Afficher la liste des menus du restaurant deja confectionné
     */
    public function get_menu_is_confectioned(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 30);
        $page = (int) $request->input('page', 1);

        $query = MenuRestaurant::with([
            'creator',
            'updater',
            'medias',
            'category',
            'complements:uuid,name'
        ])
            ->where('is_active', true);

        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_menus_restaurants')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderByRaw("
        CASE
            -- 1. Menus AVEC complément (et qui ne sont pas des boissons ou compléments bruts)
            WHEN is_generated_from_complement = 0
                 AND is_drinks = 0
                 AND have_complements = 1 THEN 1

            -- 2. Menus SANS complément
            WHEN is_generated_from_complement = 0
                 AND is_drinks = 0
                 AND have_complements = 0 THEN 2

            -- 3. Les compléments seuls
            WHEN is_generated_from_complement = 1
                 AND is_menu = 1 THEN 3

            -- 4. Les boissons
            WHEN is_drinks = 1
                 OR (is_generated_from_complement = 1 AND is_drinks = 1) THEN 4

            ELSE 5
        END ASC
    ");

        $query->orderBy('name', 'ASC');

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }





    /**
     * Display a listing of the resource.
     * @permission MenuRestaurantController::destroy
     * @permission_desc Supprimer les menus du restaurant
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Validation du mot de passe
        $request->validate([
            'password' => 'required|string'
        ], [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.string'   => 'Le mot de passe doit être une chaîne de caractères.'
        ]);

        // 🔹 Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        // 🔹 Récupère le menu restaurant ou renvoie 404
        $menu_restaurant = MenuRestaurant::findOrFail($uuid);

        // 🔹 Suppression soft
        $menu_restaurant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Menu restaurant supprimé avec succès.'
        ]);
    }

    public function get_price_by_menus_and_clients(Request $request)
    {
        $validated = $request->validate([
            'menu_uuid'   => ['required', 'uuid', 'exists:menus_restaurants,uuid'],
            'client_type' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
        ]);

        $menu = MenuRestaurant::findOrFail($validated['menu_uuid']);
        $clientType = $validated['client_type'];

        $pricesArray = [];

        if ($clientType === TypeClientsForPaiment::DEBTOR->value) {
            $pricesArray = is_array($menu->unit_price) ? $menu->unit_price : [$menu->unit_price];
        } elseif ($clientType === TypeClientsForPaiment::PARTNER->value) {
            $pricesArray = is_array($menu->special_price) ? $menu->special_price : [$menu->special_price];
        } elseif ($clientType === TypeClientsForPaiment::FREE->value) {
            $pricesArray = is_array($menu->free_price) ? $menu->free_price : [$menu->free_price];
        }

        $filteredPrices = array_filter($pricesArray, function ($value) {
            return $value !== null && $value !== '';
        });

        $prices = array_map('intval', $filteredPrices);

        $prices = array_values($prices);

        return response()->json([
            'status'      => 'success',
            'menu'        => $menu->name,
            'client_type' => $clientType,
            'prices'      => $prices,
            'min_price'   => count($prices) ? min($prices) : 0,
            'max_price'   => count($prices) ? max($prices) : 0,
        ]);
    }

    public function get_price_by_drink_and_client(Request $request)
    {
        $validated = $request->validate([
            'drink_restaurant_uuid' => ['required', 'uuid', 'exists:restaurant_drink_configurations,uuid'],
            'client_type' => ['required', 'string', new Enum(TypeClientsForPaiment::class)],
        ]);

        $drinkConfig = RestaurantDrinkConfiguration::where('uuid', $validated['drink_restaurant_uuid'])
            ->firstOrFail();

        $clientType = $validated['client_type'];

        $pricesArray = match ($clientType) {
            TypeClientsForPaiment::DEBTOR->value => $drinkConfig->prices_for_clients_debtor,
            TypeClientsForPaiment::PARTNER->value => $drinkConfig->prices_for_clients_partner,
            TypeClientsForPaiment::FREE->value => $drinkConfig->prices_for_clients_free,
            default => []
        };

        $prices = array_values(array_filter(
            is_array($pricesArray) ? $pricesArray : [$pricesArray],
            fn($v) => $v !== null
        ));

        return response()->json([
            'status' => 'success',
            'drink_name' => $drinkConfig->drink_name,
            'client_type' => $clientType,
            'prices' => $prices,
            'min_price' => count($prices) ? min($prices) : 0,
            'max_price' => count($prices) ? max($prices) : 0,
        ]);
    }


    public function get_complements_for_menu(Request $request, string $menu_uuid)
    {
        $menu = MenuRestaurant::where('uuid', $menu_uuid)
            ->select([
                'uuid',
                'name',
                'have_complements',
                'have_drinks',
                'type_complement_menu',
                'quantity_for_type_complement_menu',
                'type_complement_boisson',
                'quantity_for_type_complement_boisson'
            ])
            ->first();

        if (!$menu) {
            return response()->json([
                'status' => 'error',
                'message' => 'Menu introuvable.'
            ], 404);
        }

        $query = $menu->complements()->where('configurations_complements.is_active', true);
        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->where('configurations_complements.name', 'LIKE', '%' . $searchTerm . '%');
        }

        $complements = $query->get();

        return response()->json([
            'status' => 'success',
            'menu_config' => [
                'name' => $menu->name,
                'have_complements'                     => (bool) $menu->have_complements,
                'have_drinks'                          => (bool) $menu->have_drinks,
                'type_complement_menu'                 => $menu->type_complement_menu,
                'quantity_for_type_complement_menu'    => (int) $menu->quantity_for_type_complement_menu,
                'type_complement_boisson'              => $menu->type_complement_boisson,
                'quantity_for_type_complement_boisson' => (int) $menu->quantity_for_type_complement_boisson,
            ],
            'data' => $complements
        ], 200);
    }






}

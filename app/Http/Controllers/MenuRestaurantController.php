<?php

namespace App\Http\Controllers;

use App\Enums\MenuTypeComplementBoisson;
use App\Enums\TypeClientsForPaiment;
use App\Models\MenuRestaurant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;

/**
 * @permission_category Gestion des menus du restaurant
 */
class MenuRestaurantController extends Controller
{

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
            if ($request->has('type_complement_boisson')) {
                $request->merge([
                    'type_complement_boisson' => json_decode($request->type_complement_boisson, true),
                ]);
            }

            // ✅ LAISSER Laravel gérer la validation
            $validated = $request->validate([
                'name'            => 'required|string|max:255|unique:menus_restaurants,name',
                'category_uuid'   => 'required|exists:menu_categories,uuid',
                'image_file'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'unit_price'      => 'required|array|min:1',
                'unit_price.*'    => 'required|numeric|min:0',
                'special_price'   => 'nullable|array',
                'special_price.*' => 'numeric|min:0',
                'description'     => 'nullable|string',
                'type_complement_boisson' => 'nullable', 'json',
            ]);

            $validated['created_by'] = $auth->id;

            $menu = MenuRestaurant::create($validated);

            if ($request->hasFile('image_file')) {
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
                'message' => 'Menu restaurant créé avec succès.',
                'data'    => $menu->fresh()
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Erreur création menu:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Erreur serveur',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
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
            // 🔹 Récupérer le menu à mettre à jour
            $menu = MenuRestaurant::where('uuid', $uuid)->firstOrFail();

            // 🔹 Décoder les tableaux JSON envoyés depuis Angular
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

            if ($request->has('type_complement_boisson')) {
                $request->merge([
                    'type_complement_boisson' => json_decode($request->type_complement_boisson, true),
                ]);
            }

            // 🔹 Validation
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:menus_restaurants,name,' . $menu->uuid . ',uuid',
                'category_uuid'   => 'required|exists:menu_categories,uuid',
                'image_file'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'unit_price'      => 'required|array|min:1',
                'unit_price.*'    => 'required|numeric|min:0',
                'special_price'   => 'nullable|array',
                'special_price.*' => 'numeric|min:0',
                'description'     => 'nullable|string',
                'type_complement_boisson' => 'nullable', 'json',
            ]);

            $validated['updated_by'] = $auth->id;

            // 🔹 Mise à jour des champs
            $menu->update($validated);

            // 🔹 Gestion de l'image
            if ($request->hasFile('image_file')) {
                // Supprimer l'ancienne image si nécessaire
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
                'data'    => $menu->fresh()
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
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
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
        $menu_restaurant = MenuRestaurant::with(["creator","updater","category"])->where("uuid", $uuid)->firstOrFail();
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
     * @permission MenuRestaurantController::index
     * @permission_desc Afficher la liste des menus du restaurant
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = MenuRestaurant::with([
            'creator',
            'updater',
            'medias',
            'category'
        ]);

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
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // 🔹 Pagination
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
            'client_type' => ['required', 'string', new Enum(TypeClientsForPaiment::class)], // debtor, partner, free
        ]);

        $menu = MenuRestaurant::findOrFail($validated['menu_uuid']);
        $clientType = $validated['client_type'];

        // 🔹 Sélectionner le tableau de prix selon le type de client
        $pricesArray = [];

        if ($clientType === TypeClientsForPaiment::DEBTOR->value) {
            $pricesArray = is_array($menu->unit_price) ? $menu->unit_price : [$menu->unit_price];
        } elseif ($clientType === TypeClientsForPaiment::PARTNER->value) {
            $pricesArray = is_array($menu->special_price) ? $menu->special_price : [$menu->special_price];
        } elseif ($clientType === TypeClientsForPaiment::FREE->value) {
            $pricesArray = is_array($menu->free_price) ? $menu->free_price : [$menu->free_price];
        }

        // 🔹 Nettoyer les valeurs nulles et convertir en int
        $prices = array_map('intval', array_filter($pricesArray));

        return response()->json([
            'status'      => 'success',
            'menu'        => $menu->name,
            'client_type' => $clientType,
            'prices'      => $prices,  // 🔹 retourne un tableau de prix
            'min_price'   => count($prices) ? min($prices) : 0,  // optionnel : prix minimum
            'max_price'   => count($prices) ? max($prices) : 0,  // optionnel : prix maximum
        ]);
    }






}

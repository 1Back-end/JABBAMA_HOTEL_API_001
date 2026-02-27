<?php

namespace App\Http\Controllers;

use App\Models\SettingRestaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/**
 * @permission_category Paramètres de facturations du restaurant
 * @permission_module Gestion du restaurant
 */
class SettingRestaurantController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission SettingRestaurantController::store
     * @permission_desc Créer les paramètres de facturations du restaurant
     */
    public function store(Request $request) {
        $auth = auth()->user();

        $validated = $request->validate([
            'key' => ['required', 'string','unique:settings_restaurants,key'],
            'description' => ['required', 'string'],
            'value' => ['required', 'string'],
        ]);
        $validated['created_by'] = $auth->id;

        $settin_restaurant = SettingRestaurant::create($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Paramètre créée avec succès.',
            'data'    => $settin_restaurant->fresh(),
        ], 201);
    }


    /**
     * Display a listing of the resource.
     * @permission SettingRestaurantController::update
     * @permission_desc Modifier les paramètres de facturations du restaurant
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        $setting = SettingRestaurant::findOrFail($uuid);

        $validated = $request->validate([
            'key' => ['required', 'string', \Illuminate\Validation\Rule::unique('settings_restaurants', 'key')->ignore($setting->uuid, 'uuid')],
            'description' => ['required', 'string'],
            'value' => ['required', 'string'],
        ]);

        $validated['updated_by'] = $auth->id;

        $setting->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Paramètre mis à jour avec succès.',
            'data'    => $setting->fresh(),
        ], 200);
    }


    /**
     * Display a listing of the resource.
     * @permission SettingRestaurantController::index
     * @permission_desc Afficher la liste des paramètres de facturations du restaurant
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 3);
        $page = $request->input('page', 1);

        $query = SettingRestaurant::with(['creator', 'updater'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('key', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('value', 'like', "%{$search}%");
            });
        }

        $units = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $units->items(),
            'current_page' => $units->currentPage(),
            'last_page'    => $units->lastPage(),
            'total'        => $units->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission SettingRestaurantController::toggleActive
     * @permission_desc Activer/Désactiver les paramètres de facturations du restaurant
     */
    public function toggleActive($uuid)
    {
        $auth = auth()->user();

        // 🔹 Récupérer le module
        $settings_restaurants = SettingRestaurant::findOrFail($uuid);

        // 🔹 Bascule le statut
        $settings_restaurants->is_active = !$settings_restaurants->is_active;
        $settings_restaurants->updated_by = $auth->id;
        $settings_restaurants->save();

        return response()->json([
            'message' => $settings_restaurants->is_active ? 'Paramètre activé avec succès' : 'Paramètre désactivé avec succès',
            'settings_restaurants' => $settings_restaurants
        ], 200);
    }

    public function get_all_settings_restaurants(): JsonResponse
    {
        $settings = SettingRestaurant::where('is_active', true)->get();
        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }



}

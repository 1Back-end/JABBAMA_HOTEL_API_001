<?php

namespace App\Http\Controllers;

use App\Models\MenuRestaurant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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

            $validated = $request->validate([
                'name'          => 'required|string|max:255|unique:menus_restaurants,name',
                'category_uuid' => 'required|exists:menu_categories,uuid',
                'image_file'    => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'unit_price'    => 'required|numeric|min:0',
                'special_price' => 'required|numeric|min:0',
                'description'   => 'nullable|string'
            ], [
                'name.required' => 'Le nom du menu est obligatoire.',
                'name.string'   => 'Le nom du menu doit être une chaîne de caractères.',
                'name.max'      => 'Le nom du menu ne doit pas dépasser 255 caractères.',
                'name.unique'   => 'Un menu avec ce nom existe déjà.',

                'image_file.image' => 'Le fichier doit être une image valide.',
                'image_file.mimes' => 'L’image doit être au format jpeg, png, jpg, gif ou svg.',
                'image_file.max'   => 'La taille de l’image ne doit pas dépasser 2 Mo.',

                'unit_price.required' => 'Le prix unitaire est obligatoire.',
                'unit_price.numeric'  => 'Le prix unitaire doit être un nombre.',
                'unit_price.min'      => 'Le prix unitaire doit être supérieur ou égal à 0.',

                'special_price.required' => 'Le prix spécial est obligatoire.',
                'special_price.numeric'  => 'Le prix spécial doit être un nombre.',
                'special_price.min'      => 'Le prix spécial doit être supérieur ou égal à 0.',
            ]);

            $validated['created_by'] = $auth->id;

            $menu = MenuRestaurant::create($validated);

            // 🖼️ Gestion image
            if ($request->hasFile('image_file')) {

                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('menus_restaurants', 'public');

                $menu->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Menu restaurant créé avec succès.',
                'data'    => $menu->fresh()
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du menu.',
                'error'   => $e->getMessage()
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

            $validated = $request->validate([
                'name'          => 'required|string|max:255|unique:menus_restaurants,name,' . $menu->uuid . ',uuid',
                'category_uuid' => 'required|exists:menu_categories,uuid',
                'image_file'    => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'unit_price'    => 'required|numeric|min:0',
                'special_price' => 'required|numeric|min:0',
                'description'   => 'nullable|string'
            ], [
                'name.required' => 'Le nom du menu est obligatoire.',
                'name.string'   => 'Le nom du menu doit être une chaîne de caractères.',
                'name.max'      => 'Le nom du menu ne doit pas dépasser 255 caractères.',
                'name.unique'   => 'Un menu avec ce nom existe déjà.',

                'image_file.image' => 'Le fichier doit être une image valide.',
                'image_file.mimes' => 'L’image doit être au format jpeg, png, jpg, gif ou svg.',
                'image_file.max'   => 'La taille de l’image ne doit pas dépasser 2 Mo.',

                'unit_price.required' => 'Le prix unitaire est obligatoire.',
                'unit_price.numeric'  => 'Le prix unitaire doit être un nombre.',
                'unit_price.min'      => 'Le prix unitaire doit être supérieur ou égal à 0.',

                'special_price.required' => 'Le prix spécial est obligatoire.',
                'special_price.numeric'  => 'Le prix spécial doit être un nombre.',
                'special_price.min'      => 'Le prix spécial doit être supérieur ou égal à 0.',
            ]);

            // 👤 Audit
            $validated['updated_by'] = $auth->id;

            $menu->update($validated);

            // 🖼️ Gestion image
            if ($request->hasFile('image_file')) {

                // Supprimer l’ancienne image
                if ($media = $menu->medias()->first()) {
                    Storage::disk($media->disk)->delete($media->path);
                    $media->delete();
                }

                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('menus_restaurants', 'public');

                $menu->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Menu restaurant mis à jour avec succès.',
                'data'    => $menu->fresh()
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour du menu.',
                'error'   => $e->getMessage()
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


}

<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use App\Models\MenuRestaurant;
use App\Models\RestaurantTable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @permission_category Gestion des catégories de menus
 */
class MenuCategoryController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission MenuCategoryController::store
     * @permission_desc Créer les catégories de menus
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'name'     => 'required|string|unique:menu_categories,name',
                'position' => 'required|integer|min:0',
                'description' => 'nullable|string'
            ], [
                'name.required'     => 'Le nom de la catégorie est obligatoire.',
                'name.string'       => 'Le nom de la catégorie doit être une chaîne de caractères.',
                'name.unique'       => 'Cette catégorie existe déjà.',
                'position.required' => 'La position de la catégorie est obligatoire.',
                'position.integer'  => 'La position doit être un nombre entier.',
                'position.min'      => 'La position doit être au minimum 0.',
            ]);

            $positionExists = MenuCategory::where('position', $validated['position'])
                ->whereNull('deleted_at')
                ->exists();

            if ($positionExists) {
                return response()->json([
                    'message' => 'Cette position est déjà utilisée par une autre catégorie active.'
                ], 422);
            }
            $validated['slug'] = \Str::slug($validated['name']);
            $validated['created_by'] = $auth->id;

            // 🔹 Création de la catégorie
            $category = MenuCategory::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Catégorie créée avec succès',
                'data'    => $category
            ], 201);

        } catch (\Exception $e) {
            // 🔹 Rollback si erreur
            DB::rollBack();

            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du menu.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission MenuCategoryController::update
     * @permission_desc Modifier les catégories de menus
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Récupération de la catégorie
        $category = MenuCategory::find($uuid);

        if (!$category) {
            return response()->json([
                'message' => 'Catégorie introuvable.'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'name'     => 'sometimes|required|string|unique:menu_categories,name,' . $category->uuid . ',uuid',
                'position' => 'sometimes|required|integer|min:0',
                'description' => 'nullable|string'
            ], [
                'name.required'     => 'Le nom de la catégorie est obligatoire.',
                'name.string'       => 'Le nom de la catégorie doit être une chaîne de caractères.',
                'name.unique'       => 'Cette catégorie existe déjà.',
                'position.required' => 'La position de la catégorie est obligatoire.',
                'position.integer'  => 'La position doit être un nombre entier.',
                'position.min'      => 'La position doit être au minimum 0.',
            ]);

            if (isset($validated['position'])) {
                $positionExists = MenuCategory::where('position', $validated['position'])
                    ->whereNull('deleted_at') // ignore les catégories supprimées
                    ->where('uuid', '!=', $category->uuid) // ignore la catégorie actuelle
                    ->exists();

                if ($positionExists) {
                    return response()->json([
                        'message' => 'Cette position est déjà utilisée par une autre catégorie active.'
                    ], 422);
                }

                $category->position = $validated['position'];
            }


            // 🔹 Mise à jour des champs
            if (isset($validated['name'])) {
                $category->name = $validated['name'];
                $category->slug = \Str::slug($validated['name']); // génération automatique du slug
            }

            if (isset($validated['position'])) {
                $category->position = $validated['position'];
            }

            $category->updated_by = $auth->id;
            $category->save();

            DB::commit();

            return response()->json([
                'message' => 'Catégorie mise à jour avec succès.',
                'data'    => $category->fresh()
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour de la catégorie.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission MenuCategoryController::show
     * @permission_desc Afficher les détails d'une catégories de menus
     */
    public function show(string $uuid)
    {
        try {
            $category = MenuCategory::with(['creator', 'updater'])->where('uuid', $uuid)->first();

            if (!$category) {
                return response()->json([
                    'message' => 'Catégorie introuvable.'
                ], 404);
            }

            return response()->json([
                'message' => 'Catégorie récupérée avec succès.',
                'data'    => $category
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la récupération de la catégorie.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission MenuCategoryController::update_status
     * @permission_desc Activer/Désactiver les catégories de menus
     */
    public function update_status(Request $request, string $uuid)
    {
        $auth = auth()->user();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'is_active' => 'required|boolean',
            ], [
                'is_active.required' => 'Le statut est obligatoire.',
                'is_active.boolean'  => 'Le statut doit être vrai ou faux.',
            ]);

            // 🔹 Récupération de la catégorie
            $category = MenuCategory::find($uuid);

            if (!$category) {
                return response()->json([
                    'message' => 'Catégorie introuvable.'
                ], 404);
            }

            // 🔹 Mise à jour du statut
            $category->is_active = $validated['is_active'];
            $category->updated_by = $auth->id;
            $category->save();

            return response()->json([
                'message' => $category->is_active
                    ? 'Catégorie activée avec succès.'
                    : 'Catégorie désactivée avec succès.',
                'data' => $category
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour du statut.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission MenuCategoryController::index
     * @permission_desc Afficher la liste des catégories de menus
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = MenuCategory::with([
            'creator',
            'updater',
        ]);

        // 🔹 Filtre par statut actif/inactif
        if ($request->has('is_active')) {
            $isActive = $request->input('is_active') === 'true' ? true : false;
            $query->where('is_active', $isActive);
        }

        // 🔹 Permissions
        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_category_menus')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
            });
        }

        // 🔹 Recherche
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // 🔹 Pagination et tri par position
        $data = $query->orderBy('position', 'asc')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission MenuCategoryController::destroy
     * @permission_desc Supprimer les catégories de menus
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();
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
        $category = MenuCategory::where('uuid', $uuid)->first();
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Catégorie introuvable.'
            ], 404);
        }
        $isUsed = MenuRestaurant::where('category_uuid', $category->uuid)->exists();
        if ($isUsed) {
            return response()->json([
                'success' => false,
                'message' => "Impossible de supprimer : cette catégorie est déjà utilisée par un ou plusieurs menus."
            ], 409); // 409 Conflict
        }

        // 🔹 Suppression de la catégorie
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Catégorie supprimée avec succès.'
        ]);
    }






}

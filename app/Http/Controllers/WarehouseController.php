<?php

namespace App\Http\Controllers;

use App\DTO\WarehouseFilterData;
use App\Exports\InventoryByPointExport;
use App\Exports\InventoryExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\SuppliersExport;
use App\Exports\WarehousesExport;
use App\Exports\WarehousesExportAll;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\Warehouse;
use App\Models\WarehouseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @permission_category Gestion des entrepôts
 * @permission_module Gestion des stocks
 * @permission_module Gestion du restaurant
 */
class WarehouseController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission WarehouseController::index
     * @permission_desc Afficher la liste des entrepôts
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $roleIds = $auth->roles->pluck('id');


        $query = Warehouse::with(['creator', 'updater', 'natures', 'managers']);

        // 🔥 Filtrer selon le rôle
        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_warehouses')) {

            $query->where(function ($q) use ($auth, $roleIds) {

                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('managers.roles', function ($qr) use ($roleIds) {
                        $qr->whereIn('roles.id', $roleIds);
                    });
                }

                else {
                    $q->whereHas('managers', function ($qr) use ($auth) {
                        $qr->where('user_id', $auth->id);
                    });
                }

            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('stock_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('total_stock', 'like', "%{$search}%"); // ✅ corrigé
            })
                ->orWhereHas('natures', function ($qw) use ($search) {
                    $qw->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('abbreviation', 'like', "%{$search}%");
                })
                ->orWhereHas('managers', function ($ma) use ($search) {
                    $ma->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        }

        $warehouses = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $warehouses->items(),
            'current_page' => $warehouses->currentPage(),
            'last_page'    => $warehouses->lastPage(),
            'total'        => $warehouses->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::get_warehouses_is_used_for_restaurant
     * @permission_desc Afficher la liste des entrepôts (Cuisine) utilisés pour le restaurant
     */
    public function get_warehouses_is_used_for_restaurant(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $roleIds = $auth->roles->pluck('id');

        $query = Warehouse::with(['creator', 'updater', 'natures', 'managers'])->where('is_used_for_restaurant', true);;

        // 🔥 Filtrer selon le rôle
        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_warehouses')) {

            $query->where(function ($q) use ($auth, $roleIds) {

                // 👉 Utilisateurs avec view_role_related_data : voient tous les entrepôts gérés par des utilisateurs ayant le même rôle
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('managers.roles', function ($qr) use ($roleIds) {
                        $qr->whereIn('roles.id', $roleIds);
                    });
                }

                // 👉 Autres utilisateurs : voir uniquement les entrepôts dont ils sont managers
                else {
                    $q->whereHas('managers', function ($qr) use ($auth) {
                        $qr->where('user_id', $auth->id);
                    });
                }

            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('stock_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('total_stock', 'like', "%{$search}%"); // ✅ corrigé
            })
                ->orWhereHas('natures', function ($qw) use ($search) {
                    $qw->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('abbreviation', 'like', "%{$search}%");
                })
                ->orWhereHas('managers', function ($ma) use ($search) {
                    $ma->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        }

        $warehouses = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $warehouses->items(),
            'current_page' => $warehouses->currentPage(),
            'last_page'    => $warehouses->lastPage(),
            'total'        => $warehouses->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::get_warehouses_is_bar_warehouse
     * @permission_desc Afficher la liste des entrepôts (Bar) utilisés pour le restaurant
     */
    public function get_warehouses_is_bar_warehouse(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $roleIds = $auth->roles->pluck('id');

        $query = Warehouse::with(['creator', 'updater', 'natures', 'managers'])->where('is_bar_warehouse', true);;

        // 🔥 Filtrer selon le rôle
        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_warehouses')) {

            $query->where(function ($q) use ($auth, $roleIds) {

                // 👉 Utilisateurs avec view_role_related_data : voient tous les entrepôts gérés par des utilisateurs ayant le même rôle
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('managers.roles', function ($qr) use ($roleIds) {
                        $qr->whereIn('roles.id', $roleIds);
                    });
                }

                // 👉 Autres utilisateurs : voir uniquement les entrepôts dont ils sont managers
                else {
                    $q->whereHas('managers', function ($qr) use ($auth) {
                        $qr->where('user_id', $auth->id);
                    });
                }

            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('stock_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('total_stock', 'like', "%{$search}%"); // ✅ corrigé
            })
                ->orWhereHas('natures', function ($qw) use ($search) {
                    $qw->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('abbreviation', 'like', "%{$search}%");
                })
                ->orWhereHas('managers', function ($ma) use ($search) {
                    $ma->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        }

        $warehouses = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $warehouses->items(),
            'current_page' => $warehouses->currentPage(),
            'last_page'    => $warehouses->lastPage(),
            'total'        => $warehouses->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::store
     * @permission_desc Créer des entrepôts
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        // 🔥 Validation
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:warehouses,name',
            'stock_type'  => 'required|string|max:255',
            'address'     => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_primary'  => 'required|boolean',
            'managers'    => 'required|array|min:1',
            'managers.*'  => 'required|exists:users,id',
            'natures'     => 'required|array|min:1',
            'natures.*'   => 'required|exists:nature_entrepots,uuid',
            'is_used_for_restaurant' => 'nullable|boolean',
            'is_bar_warehouse'  => 'nullable|boolean',
        ]);

        /**
         * ────────────────────────────────────────────────
         * 🔥 RÈGLE 1 : entrepôt principal = 1 seul manager
         * ────────────────────────────────────────────────
         */
        if ($validated['is_primary'] && count($validated['managers']) !== 1) {
            return response()->json([
                'success' => false,
                'message' => "Un entrepôt principal doit avoir exactement un seul manager.",
            ], 422);
        }

        /**
         * ────────────────────────────────────────────────
         * 🔥 RÈGLE 2 : il ne doit exister qu'un seul entrepôt principal
         * ────────────────────────────────────────────────
         */
        if ($validated['is_primary']) {
            $existsPrimary = Warehouse::where('is_primary', true)->exists();

            if ($existsPrimary) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt principal.",
                ], 422);
            }
        }

        // 🔹 Entrepôt cuisine (restaurant)
        if (!empty($validated['is_used_for_restaurant']) && $validated['is_used_for_restaurant']) {

            $existsKitchen = Warehouse::where('is_used_for_restaurant', true)->exists();

            if ($existsKitchen) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt cuisine pour le restaurant.",
                ], 422);
            }
        }

        // 🔹 Entrepôt bar
        if (!empty($validated['is_bar_warehouse']) && $validated['is_bar_warehouse']) {

            $existsBar = Warehouse::where('is_bar_warehouse', true)->exists();

            if ($existsBar) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt bar pour le restaurant.",
                ], 422);
            }
        }

        // Ajout created_by
        $validated['created_by'] = $auth->id;

        // 🔥 Création de l'entrepôt
        $warehouse = Warehouse::create($validated);

        // 🔥 Association des natures
        $natures = collect($validated['natures'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'is_active'  => true,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);
        $warehouse->natures()->sync($natures);

        // 🔥 Association des managers
        $managers = collect($validated['managers'])->mapWithKeys(fn($managerId) => [
            $managerId => [
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);
        $warehouse->managers()->sync($managers);

        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été créé avec succès.",
            'data'    => $warehouse->load(['creator', 'updater', 'natures', 'managers']),
        ], 201);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::show
     * @permission_desc Afficher les détails des entrepôts
     */
    public function show(string $uuid)
    {
        $warehouse = Warehouse::with([
            'managers',
            'natures',
            'creator',
            'updater',
        ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => "Détails de l'entrepôt récupérés avec succès.",
            'data'    => $warehouse
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::update
     * @permission_desc Modifier des entrepôts
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();
        $warehouse = Warehouse::where('uuid', $uuid)->firstOrFail();

        // 🔹 Validation
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:warehouses,name,' . $warehouse->uuid . ',uuid',
            'stock_type'  => 'required|string|max:255',
            'address'     => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_primary'  => 'required|boolean',
            'managers'    => 'required|array|min:1',
            'managers.*'  => 'required|exists:users,id',
            'natures'     => 'required|array|min:1',
            'natures.*'   => 'required|exists:nature_entrepots,uuid',
            'is_used_for_restaurant' => 'nullable|boolean',
            'is_bar_warehouse'  => 'nullable|boolean',
        ]);

        // 🔹 Règle entrepôt principal = 1 seul manager
        if ($validated['is_primary'] && count($validated['managers']) !== 1) {
            return response()->json([
                'success' => false,
                'message' => "Un entrepôt principal doit avoir exactement un seul manager.",
            ], 422);
        }

        // 🔹 Vérification qu’il n’y a qu’un seul entrepôt principal
        if ($validated['is_primary']) {
            $alreadyPrimary = Warehouse::where('uuid', '!=', $warehouse->uuid)
                ->where('is_primary', true)
                ->exists();

            if ($alreadyPrimary) {
                return response()->json([
                    'success' => false,
                    'message' => "Un seul entrepôt principal est autorisé. Un autre est déjà défini comme principal.",
                ], 422);
            }
        }

        // 🔹 Vérification : un seul entrepôt cuisine
        if (!empty($validated['is_used_for_restaurant']) && $validated['is_used_for_restaurant']) {

            $existsKitchen = Warehouse::where('is_used_for_restaurant', true)
                ->where('uuid', '!=', $warehouse->uuid)
                ->exists();

            if ($existsKitchen) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt cuisine pour le restaurant.",
                ], 422);
            }
        }

        // 🔹 Vérification : un seul entrepôt bar
        if (!empty($validated['is_bar_warehouse']) && $validated['is_bar_warehouse']) {

            $existsBar = Warehouse::where('is_bar_warehouse', true)
                ->where('uuid', '!=', $warehouse->uuid)
                ->exists();

            if ($existsBar) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt bar pour le restaurant.",
                ], 422);
            }
        }

        // 🔹 Mise à jour de l'entrepôt
        $validated['updated_by'] = $auth->id;
        $warehouse->update($validated);

        // 🔹 Suppression définitive des anciens pivots natures
        \Illuminate\Support\Facades\DB::table('nature_warehouse')
            ->where('warehouse_uuid', $warehouse->uuid)
            ->delete();

        // 🔹 Suppression définitive des anciens pivots managers
        \Illuminate\Support\Facades\DB::table('warehouse_managers')
            ->where('warehouse_uuid', $warehouse->uuid)
            ->delete();

        // 🔹 Préparer les nouvelles natures
        $natures = collect($validated['natures'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'is_active'  => true,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);

        // 🔹 Préparer les nouveaux managers
        $managers = collect($validated['managers'])->mapWithKeys(fn($managerId) => [
            $managerId => [
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);

        // 🔹 Synchronisation
        $warehouse->natures()->sync($natures);
        $warehouse->managers()->sync($managers);

        // 🔹 Réponse finale
        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été mis à jour avec succès.",
            'data'    => $warehouse->load(['creator', 'updater', 'natures', 'managers']),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission WarehouseController::destroy
     * @permission_desc Supprimer des entrepôts
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $warehouse = Warehouse::findOrFail($uuid);

        if ($warehouse->orders()->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de supprimer cet entrepot : des commandes lui sont associées.'
            ], 422);
        }

        $warehouse->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été supprimé avec succès."
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::update_status
     * @permission_desc Activer/Désactiver des entrepôts
     */
    public function update_status(Request $request, $uuid){
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $warehouse = Warehouse::findOrFail($uuid);
        $warehouse->is_active = $request->is_active;
        $warehouse->updated_by = $auth->id;
        $warehouse->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
    }

    public function get_all_warehouses_by_users(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');

        $query = Warehouse::with([
            'natures',
            'managers',
            'products' => function ($query) {
                $query->wherePivot('is_active', true); // uniquement produits actifs
            }
        ]);

        // 🔹 Filtrer selon rôle et permissions
        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_warehouses')) {

            $query->where(function ($q) use ($auth, $roleIds) {

                // 👉 Utilisateurs avec view_role_related_data : voir entrepôts gérés par les utilisateurs du même rôle
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('managers.roles', function ($qr) use ($roleIds) {
                        $qr->whereIn('roles.id', $roleIds);
                    });
                }

                // 👉 Autres utilisateurs : voir uniquement les entrepôts dont ils sont managers
                else {
                    $q->whereHas('managers', function ($qr) use ($auth) {
                        $qr->where('user_id', $auth->id);
                    });
                }

            });
        }

        $warehouses = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des entrepôts et leurs produits pour l’utilisateur connecté.',
            'data'    => $warehouses,
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::get_products_by_warehouse
     * @permission_desc Afficher les articles par entrepôts
     */
    public function get_products_by_warehouse($uuid, Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Product::with([
            'creator',
            'updater',
            'category',
            'unitMeasure',
            'subCategories',
            'medias',
            'points' => function ($q) use ($uuid) {
                $q->where('warehouses.uuid', $uuid);
            }
        ])->whereHas('points', function ($q) use ($uuid) {
            $q->where('warehouses.uuid', $uuid);
        });

        // 🔹 Filtre catégorie
        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }

        // 🔹 Filtre unité
        if ($request->filled('unit_uuid')) {
            $query->where('unit_uuid', $request->unit_uuid);
        }

        // 🔹 Recherche globale
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('unitMeasure', fn($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $products = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }



    public function get_products_by_warehouse_is_used_for_restaurant($uuid, Request $request)
    {
        $perPage = $request->input('limit', 10);
        $page = $request->input('page', 1);

        $query = Product::with([
            'creator',
            'updater',
            'category',
            'unitMeasure',
            'subCategories',
            'medias',
            'points' => function ($q) use ($uuid) {
                $q->where('warehouses.uuid', $uuid);
            }
        ])->whereHas('points', function ($q) use ($uuid) {
            $q->where('warehouses.uuid', $uuid);
        });

        // 🔹 Filtre catégorie
        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }

        // 🔹 Filtre unité
        if ($request->filled('unit_uuid')) {
            $query->where('unit_uuid', $request->unit_uuid);
        }

        // 🔹 Recherche globale
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('unitMeasure', fn($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $products = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }


    public function get_products_by_warehouse_is_bar_warehouse($uuid, Request $request)
    {
        $perPage = $request->input('limit', 10);
        $page = $request->input('page', 1);

        $query = Product::with([
            'creator',
            'updater',
            'category',
            'unitMeasure',
            'subCategories',
            'medias',
            'points' => function ($q) use ($uuid) {
                $q->where('warehouses.uuid', $uuid);
            }
        ])->whereHas('points', function ($q) use ($uuid) {
            $q->where('warehouses.uuid', $uuid);
        });

        // 🔹 Filtre catégorie
        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }

        // 🔹 Filtre unité
        if ($request->filled('unit_uuid')) {
            $query->where('unit_uuid', $request->unit_uuid);
        }

        // 🔹 Recherche globale
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('unitMeasure', fn($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $products = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }


    public function get_products_bar_points(Request $request)
    {
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = Product::with([
            'creator',
            'updater',
            'category',
            'unitMeasure',
            'subCategories',
            'medias',
            'points' => function ($q) {
                $q->where('is_bar_warehouse', true); // Seulement les points du bar
            }
        ])->whereHas('points', function ($q) {
            $q->where('is_bar_warehouse', true);
        });

        // 🔹 Filtre catégorie si besoin
        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }
        // 🔹 Filtre catégorie si besoin
        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }

        // 🔹 Filtre unité si besoin
        if ($request->filled('unit_uuid')) {
            $query->where('unit_uuid', $request->unit_uuid);
        }

        // 🔹 Recherche globale
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('unitMeasure', fn($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $products = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::get_managers_by_warehouse
     * @permission_desc Afficher les managers par entrepôts
     */
    public function get_managers_by_warehouse(string $uuid, Request $request)
    {
        $search = $request->query('search', '');
        $is_active = $request->query('is_active', null); // 1 / 0 / null

        // Vérifier si l'entrepôt existe
        $warehouse = Warehouse::where('uuid', $uuid)->firstOrFail();

        // Récupérer les managers via la relation belongsToMany
        $query = $warehouse->managers();

        // Filtre recherche nom + email
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('login', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%")
                    ->orWhere('prenom', 'LIKE', "%$search%")
                    ->orWhere('nom_utilisateur', 'LIKE', "%$search%");
            });
        }

        // Filtre actif / inactif
        if (!is_null($is_active)) {
            $query->where('is_active', filter_var($is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $managers = $query->get();

        return response()->json([
            'status' => 'success',
            'warehouse' => $warehouse->name,
            'managers' => $managers
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission WarehouseController::export_inventory_by_warehouse
     * @permission_desc Exporter l'inventaire d'un entrepôt en Excel
     */
    public function export_inventory_by_warehouse(Request $request, ?string $pointUuid = null)
    {
        // 🔹 Normalisation
        $pointUuid = ($pointUuid === 'all' || empty($pointUuid)) ? null : $pointUuid;

        // 🔹 Nom du fichier
        if ($pointUuid) {
            $warehouse = Warehouse::findOrFail($pointUuid);

            $fileName = 'INVENTAIRE_'
                . strtoupper(str_replace(' ', '_', $warehouse->name))
                . '_' . now()->format('Ymd_His')
                . '.xlsx';
        } else {
            $fileName = 'INVENTAIRE_TOUS_LES_ENTREPOTS_'
                . now()->format('Ymd_His')
                . '.xlsx';
        }

        // 🔹 Export Excel
        Excel::store(
            new InventoryByPointExport($pointUuid),
            $fileName,
            'exportinventory',
            \Maatwebsite\Excel\Excel::XLSX
        );

        return response()->json([
            'message'  => 'Exportation des données effectuée avec succès',
            'filename' => $fileName,
            'url'      => Storage::disk('exportinventory')->url($fileName),
        ], 200);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::print_inventory_by_warehouse
     * @permission_desc Imprimer l'inventaire de stocks d'un entrepôt en PDF
     */
    public function print_inventory_by_warehouse(Request $request, string $point_uuid)
    {
        $auth = auth()->user();

        try {
            DB::beginTransaction();

            // ✅ Récupération des stocks de l'entrepôt
            $product_points = ProductPoint::with([
                'product',
                'point',
                'creator',
                'updater'
            ])
                ->where('point_uuid', $point_uuid)
                ->get();

            if ($product_points->isEmpty()) {
                return response()->json([
                    'message' => 'Aucun article trouvé pour cet entrepôt'
                ], 404);
            }

            // ✅ Entrepôt
            $warehouse = $product_points->first()->point;

            $fileName   = strtoupper('INVENTORY-WAREHOUSE-' . now()->format('YmdHis') . '.pdf');
            $folderPath = 'storage/inventory-warehouse/' . $warehouse->uuid;
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'warehouse'      => $warehouse,
                'product_points' => $product_points,
            ];
            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.inventory-warehouse.inventory-warehouse',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
            );

            DB::commit();
            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission WarehouseController::export_warehouse
     * @permission_desc Exporter la liste des entrepôts en Excel
     */
    public function export_warehouse(Request $request)
    {
        $filter = WarehouseFilterData::fromRequestWarehouse($request);
        $filename = 'LISTE-DES-ENTREPOTS-' . now()->format('dmY') . '.xlsx';

        $warehouseQuery = warehouse_filter($filter, false);

        Excel::store(new WarehousesExport($warehouseQuery), $filename, 'exportwarehouse');
        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $filename,
            "url" => Storage::disk('exportwarehouse')->url($filename)
        ]);

    }

    /**
     * Display a listing of the resource.
     * @permission WarehouseController::export_warehouses
     * @permission_desc Exporter la liste des Points / entrepôts
     */
    public function export_warehouses()
    {
        $fileName = 'LISTE-DES-ENTREPOTS-' . Carbon::now()->format('Y-m-d') . '.xlsx';

        Excel::store(new WarehousesExportAll(), $fileName, 'exportwarehouseall');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $fileName,
            "url" => Storage::disk('exportwarehouseall')->url($fileName)
        ]);
    }





}

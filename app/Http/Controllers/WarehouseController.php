<?php

namespace App\Http\Controllers;

use App\Exports\InventoryExport;
use App\Exports\PurchaseOrdersExport;
use App\Models\Warehouse;
use App\Models\WarehouseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @permission_category Gestion des entrepôts
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

        $query = Warehouse::with(['creator', 'updater', 'natures', 'managers']);

        // 🔥 Filtrer selon le rôle
        if (!$auth->hasRole('SUPER_ADMIN')) {
            $query->whereHas('managers', function ($q) use ($auth) {
                $q->where('user_id', $auth->id);
            });
        }

        // ✅ Filtre is_active
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // ✅ Search
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('stock_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('total_stock', 'total_stock', "%{$search}%");
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
     * @permission_desc Création des entrepôts
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
     * @permission_desc Modification des entrepôts
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
     * @permission_desc Suppression des entrepôts
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
     * @permission_desc Activation/Désactivation des entrepôts
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

        $warehouses = Warehouse::whereHas('managers', function ($query) use ($auth) {
            $query->where('users.id', $auth->id);
        })
            ->with([
                'natures',
                'managers',
                'products' => function($query) {
                    $query->wherePivot('is_active', true); // uniquement produits actifs
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des entrepôts et leurs produits pour l’utilisateur connecté.',
            'data'    => $warehouses,
        ]);
    }

    public function get_products_by_warehouse(string $uuid, Request $request)
    {
        $search = $request->query('search', '');
        $is_active = $request->query('is_active', null);

        $warehouse = Warehouse::with(['products' => function($query) use ($search, $is_active) {
            if ($search) {
                $query->where('produits.name', 'like', "%{$search}%");
            }

            // IMPORTANT : préciser la table !
            if (!is_null($is_active)) {
                $query->where('produit_point.is_active', $is_active);
                // ⚠️ ou alors `produits.is_active` selon ce que tu veux vraiment filtrer
            }
        }])->findOrFail($uuid);

        return response()->json([
            'success' => true,
            'message' => "Produits de l’entrepôt récupérés avec succès.",
            'data'    => $warehouse->products,
        ]);
    }



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

    public function export_inventory_by_warehouse(Request $request)
    {
        $request->validate([
            'warehouse_uuid' => 'required|exists:warehouses,uuid',
        ]);
        $warehouseUuid = $request->input('warehouse_uuid');
        $fileName = 'warehouses_inventory-' . Carbon::now()->format('Y-m-d_H-i-s') . '.xlsx';

        Excel::store(new InventoryExport($warehouseUuid), $fileName, 'exportinventory');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $fileName,
            "url" => Storage::disk('exportinventory')->url($fileName)
        ]);

    }
    



}

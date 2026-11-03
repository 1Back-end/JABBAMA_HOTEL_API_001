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
 * @code_module STOCK
 * @permission_module Gestion du restaurant
 * @code_module RESTAURANT
 * @permission_module Gestion du bar
 * @code_module BAR
 */
class WarehouseController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission WarehouseController::index
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher la liste des entrepôts
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = (int) $request->input('limit', 25);
        $page = (int) $request->input('page', 1);

        $roleIds = $auth->roles->pluck('id');
        $query = Warehouse::with(['creator', 'updater', 'natures', 'managers']);

        if (!$auth->hasRole('SUPER_ADMIN')) {
            $query->whereHas('managers', function($qw) use ($auth) {
                $qw->where('warehouse_managers.user_id', $auth->id)
                    ->whereNull('warehouse_managers.deleted_at');
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($mainQuery) use ($search) {
                $mainQuery->where(function ($q) use ($search) {
                    $q->where('ref', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('stock_type', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");

                    if (is_numeric($search)) {
                        $q->orWhere('total_stock', '=', $search);
                    }
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
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Créer des entrepôts
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

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
            'is_used_for_drinks_transformation' => 'nullable|boolean',
            'is_used_for_stock_flow' => 'nullable|boolean' // 🔹 Ajout ici
        ]);

        if ($validated['is_primary'] && count($validated['managers']) !== 1) {
            return response()->json([
                'success' => false,
                'message' => "Un entrepôt principal doit avoir exactement un seul manager.",
            ], 422);
        }

        if ($validated['is_primary']) {
            $existsPrimary = Warehouse::where('is_primary', true)->exists();

            if ($existsPrimary) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt principal.",
                ], 422);
            }
        }
        if (!empty($validated['is_used_for_restaurant']) && $validated['is_used_for_restaurant']) {

            $existsKitchen = Warehouse::where('is_used_for_restaurant', true)->exists();

            if ($existsKitchen) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt cuisine pour le restaurant.",
                ], 422);
            }
        }

        if (!empty($validated['is_bar_warehouse']) && $validated['is_bar_warehouse']) {

            $existsBar = Warehouse::where('is_bar_warehouse', true)->exists();

            if ($existsBar) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt bar pour le restaurant.",
                ], 422);
            }
        }

        if (!empty($validated['is_used_for_drinks_transformation']) && $validated['is_used_for_drinks_transformation']) {
            $existsWarehouse = Warehouse::where('is_used_for_drinks_transformation', true)->exists();
            if ($existsWarehouse) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt de transformation des boissons.",
                ], 422);
            }
        }

        if (!empty($validated['is_used_for_stock_flow']) && $validated['is_used_for_stock_flow']) {
            $existsStockFlow = Warehouse::where('is_used_for_stock_flow', true)->exists();
            if ($existsStockFlow) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt pour l'écoulement des stocks.",
                ], 422);
            }
        }

        $validated['created_by'] = $auth->id;

        $warehouse = Warehouse::create($validated);


        $natures = collect($validated['natures'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'is_active'  => true,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);
        $warehouse->natures()->sync($natures);

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
     * @code STOCK,BAR, RESTAURANT
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
     * @code STOCK,BAR, RESTAURANT
     * @permission_desc Modifier des entrepôts
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();
        $warehouse = Warehouse::where('uuid', $uuid)->firstOrFail();

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
            'is_used_for_drinks_transformation' => 'nullable|boolean',
            'is_used_for_stock_flow' => 'nullable|boolean'
        ]);

        if ($validated['is_primary'] && count($validated['managers']) !== 1) {
            return response()->json([
                'success' => false,
                'message' => "Un entrepôt principal doit avoir exactement un seul manager.",
            ], 422);
        }

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

        if (!empty($validated['is_used_for_drinks_transformation']) && $validated['is_used_for_drinks_transformation']) {

            $existsWarehouse = Warehouse::where('is_used_for_drinks_transformation', true)
                ->where('uuid', '!=', $warehouse->uuid)
                ->exists();

            if ($existsWarehouse) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt de transformation des boissons.",
                ], 422);
            }
        }

        if (!empty($validated['is_used_for_stock_flow']) && $validated['is_used_for_stock_flow']) {

            $existsStockFlow = Warehouse::where('is_used_for_stock_flow', true)
                ->where('uuid', '!=', $warehouse->uuid)
                ->exists();

            if ($existsStockFlow) {
                return response()->json([
                    'success' => false,
                    'message' => "Il existe déjà un entrepôt pour l'écoulement des stocks.",
                ], 422);
            }
        }

        $validated['updated_by'] = $auth->id;
        $warehouse->update($validated);

        \Illuminate\Support\Facades\DB::table('nature_warehouse')
            ->where('warehouse_uuid', $warehouse->uuid)
            ->delete();

        \Illuminate\Support\Facades\DB::table('warehouse_managers')
            ->where('warehouse_uuid', $warehouse->uuid)
            ->delete();

        $natures = collect($validated['natures'])->mapWithKeys(fn($uuid) => [
            $uuid => [
                'is_active'  => true,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);

        $managers = collect($validated['managers'])->mapWithKeys(fn($managerId) => [
            $managerId => [
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
            ],
        ]);

        $warehouse->natures()->sync($natures);
        $warehouse->managers()->sync($managers);

        return response()->json([
            'success' => true,
            'message' => "L'entrepôt a été mis à jour avec succès.",
            'data'    => $warehouse->load(['creator', 'updater', 'natures', 'managers']),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission WarehouseController::update_status
     * @code STOCK,BAR, RESTAURANT
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



    /**
     * Display a listing of the resource.
     * @permission WarehouseController::get_products_by_warehouse
     * @code STOCK,BAR, RESTAURANT
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

        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }
        if ($request->filled('unit_uuid')) {
            $query->where('unit_uuid', $request->unit_uuid);
        }
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
     * @code STOCK,BAR, RESTAURANT
     * @permission_desc Afficher les managers par entrepôts
     */
    public function get_managers_by_warehouse(string $uuid, Request $request)
    {
        $search = $request->query('search', '');
        $is_active = $request->query('is_active', null);

        $warehouse = Warehouse::where('uuid', $uuid)->firstOrFail();

        $query = $warehouse->managers();

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('login', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%")
                    ->orWhere('prenom', 'LIKE', "%$search%")
                    ->orWhere('nom_utilisateur', 'LIKE', "%$search%");
            });
        }

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
     * @permission WarehouseController::export_warehouse
     * @code STOCK,BAR, RESTAURANT
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
     * @code STOCK
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


    public function product_used_for_warehouse_bar(Request $request)
    {
        $perPage = (int) $request->input('limit', 25);
        $page = (int) $request->input('page', 1);

        $warehouseUuid = $request->input('warehouse_uuid');

        if (!$warehouseUuid) {
            $barWarehouse = Warehouse::where('is_bar_warehouse', true)->first();
            $warehouseUuid = $barWarehouse?->uuid;
        }

        $query = Product::with([
            'creator',
            'updater',
            'category',
            'unitMeasure',
            'subCategories',
            'medias',
            'points' => function ($q) use ($warehouseUuid) {
                if ($warehouseUuid) {
                    $q->where('produit_point.point_uuid', $warehouseUuid);
                }
            }
        ]);

        if ($warehouseUuid) {
            $query->whereHas('points', function ($q) use ($warehouseUuid) {
                $q->where('produit_point.point_uuid', $warehouseUuid);
            });
        }

        if ($request->filled('category_uuid')) {
            $query->where('category_uuid', $request->category_uuid);
        }

        if ($request->filled('unit_uuid')) {
            $query->where('unit_uuid', $request->unit_uuid);
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('produits.name', 'like', "%{$search}%")
                    ->orWhere('produits.code', 'like', "%{$search}%")
                    ->orWhere('produits.description', 'like', "%{$search}%")
                    ->orWhereHas('unitMeasure', fn($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $products = $query->latest('produits.created_at')->paginate($perPage, ['produits.*'], 'page', $page);

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }





}

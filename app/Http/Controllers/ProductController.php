<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\PurchaseOrdersStatus;
use App\Enums\StockAdjustmentAction;
use App\Enums\StocksAdjustmentStatus;
use App\Enums\StocksDeductionsStatus;
use App\Enums\SupplyStatus;
use App\Models\ComplementVirtualTemp;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockDeductionItem;
use App\Models\SupplyItem;
use App\Models\VirtualOrderMenuRestaurant;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @permission_category Gestion des articles
 * @permission_module Gestion des stocks
 * @code_module STOCK
 */
class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @permission ProductController::index
     * @code STOCK
     * @permission_desc Afficher la liste des articles
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = (int) $request->input('limit', 25);
        $page = (int) $request->input('page', 1);

        $pointUuid = $request->input('point_uuid');

        $query = Product::with([
            'creator',
            'updater',
            'category',
            'unitMeasure',
            'subCategories',
            'medias',
            'points' => function ($q) use ($auth, $pointUuid) {
                if (!$auth->is_super_admin) {
                    $q->whereHas('managers', function ($m) use ($auth) {
                        $m->where('warehouse_managers.user_id', $auth->id)
                            ->whereNull('warehouse_managers.deleted_at');
                    });
                }

                if ($pointUuid && $pointUuid !== 'all') {
                    $q->where('warehouses.uuid', $pointUuid);
                }
            }
        ]);

        if (!$auth->is_super_admin) {
            $query->whereHas('points', function ($q) use ($auth, $pointUuid) {
                $q->whereHas('managers', function ($m) use ($auth) {
                    $m->where('warehouse_managers.user_id', $auth->id)
                        ->whereNull('warehouse_managers.deleted_at');
                });

                if ($pointUuid && $pointUuid !== 'all') {
                    $q->where('warehouses.uuid', $pointUuid);
                }
            });
        } elseif ($pointUuid && $pointUuid !== 'all') {
            $query->whereHas('points', function ($q) use ($pointUuid) {
                $q->where('warehouses.uuid', $pointUuid);
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
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('unitMeasure', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $products = $query->orderBy('name', 'asc')
            ->paginate(
                perPage: $perPage,
                page: $page
            );

        return response()->json([
            'data'         => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'total'        => $products->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::store
     * @code STOCK
     * @permission_desc Créer un article
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            // Validation des champs
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:produits,name',
                'description' => 'nullable|string',
                'category_uuid' => 'nullable|exists:categories,uuid',
                'unit_uuid' => 'nullable|exists:units,uuid',
                'purchase_price' => 'nullable|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'minimum_stock' => 'required|integer|min:0',
                'sub_categories' => 'nullable|array',
                'sub_categories.*' => 'exists:sub_categories,uuid',
                'points' => 'nullable|array',
                'points.*.uuid' => 'required|exists:warehouses,uuid',
                'points.*.quantity' => 'nullable|integer|min:0',
                'image_file' => 'nullable|file|max:2048|mimes:jpg,jpeg,png,svg',
                'category_json' => 'nullable|array',
                'category_json.*' => 'string',
            ], [
                'name.required' => 'Le nom du produit est obligatoire.',
                'name.unique' => 'Ce nom de produit existe déjà.',
                'minimum_stock.required' => 'Le stock minimal est obligatoire.',
                'category_uuid.exists' => 'La catégorie sélectionnée n\'existe pas.',
                'unit_uuid.exists' => 'L\'unité de mesure sélectionnée n\'existe pas.',
                'sub_categories.*.exists' => 'Une sous-catégorie sélectionnée est invalide.',
                'points.*.uuid.exists' => 'Un point de dépôt sélectionné est invalide.',
                'points.*.quantity.min' => 'La quantité pour chaque point doit être au moins 0.',
                'image_file.max' => 'La taille maximale de l\'image du produit doit être de 2Mo',
                'image_file.mimes' => 'L\'image du produit doit être de type jpg, jpeg, png ou svg',
            ]);

            $validated['created_by'] = $auth->id;

            $product = Product::create($validated);

            if (!empty($validated['sub_categories'])) {
                $pivotData = [];
                foreach ($validated['sub_categories'] as $sub_uuid) {
                    $pivotData[$sub_uuid] = [
                        'is_active' => true,
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ];
                }
                $product->subCategories()->sync($pivotData);
            }

            if (!empty($validated['points'])) {
                $pivotData = [];
                foreach ($validated['points'] as $point) {
                    $pivotData[$point['uuid']] = [
                        'quantity' => $point['quantity'] ?? 0,
                        'stocks_minimal' => $point['stocks_minimal'] ?? $validated['minimum_stock'], // prend la valeur envoyée pour chaque point ou le minimum_stock global
                        'created_by' => $auth->id,
                        'updated_by' => $auth->id,
                    ];
                }
                $product->points()->sync($pivotData);
            }

            if ($request->hasFile('image_file')) {
                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('products', 'public');

                $product->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été créé avec succès !",
                'product' => $product->load(['subCategories', 'points']),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les champs saisis.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Impossible de créer le produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ProductController::update_status
     * @code STOCK
     * @permission_desc Activer/Désactiver un article
     */
    public function update_status(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            $product = Product::findOrFail($uuid);

            $request->validate([
                'is_active' => 'required|boolean',
            ], [
                'is_active.required' => 'Le statut du produit est obligatoire.',
                'is_active.boolean' => 'Le statut doit être vrai ou faux.',
            ]);

            $product->is_active = $request->is_active;
            $product->updated_by = $auth->id;
            $product->save();

            $statusText = $product->is_active ? 'activé' : 'désactivé';

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été {$statusText} avec succès !",
                'product' => $product
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier le statut fourni.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable.',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de mettre à jour le statut du produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::show
     * @code STOCK
     * @permission_desc Afficher le détail d'un article
     */
    public function show($uuid)
    {
        try {
            $product = Product::with(['category', 'unitMeasure', 'creator', 'updater','subCategories','points'])
                ->findOrFail($uuid);

            return response()->json([
                'success' => true,
                'message' => "Détails du produit '{$product->name}' récupérés avec succès.",
                'data' => $product
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable.',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les détails du produit pour le moment. Veuillez réessayer plus tard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ProductController::update
     * @code STOCK
     * @permission_desc Modifier un article
     */
    public function update_products(Request $request, $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            $product = Product::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:produits,name,' . $product->uuid . ',uuid',
                'description' => 'nullable|string',
                'category_uuid' => 'nullable|exists:categories,uuid',
                'unit_uuid' => 'nullable|exists:units,uuid',
                'purchase_price' => 'nullable|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'minimum_stock' => 'required|integer|min:0',
                'sub_categories' => 'nullable|array',
                'sub_categories.*' => 'exists:sub_categories,uuid',
                'points' => 'nullable|array',
                'points.*.uuid' => 'required|exists:warehouses,uuid',
                'points.*.quantity' => 'nullable|integer|min:0',
                'points.*.stocks_minimal' => 'nullable|integer|min:0',
                'image_file' => 'nullable|file|max:2048|mimes:jpg,jpeg,png,svg',
                'category_json' => 'nullable|array',
                'category_json.*' => 'string',
            ], [
                'name.required' => 'Le nom du produit est obligatoire.',
                'name.unique' => 'Ce nom de produit existe déjà.',
                'minimum_stock.required' => 'Le stock minimal est obligatoire.',
            ]);

            $validated['updated_by'] = $auth->id;
            $product->update($validated);


            $subCategories = $request->input('sub_categories', []);
            $pivotSub = [];
            foreach ($subCategories as $sub_uuid) {
                $pivotSub[$sub_uuid] = [
                    'is_active' => true,
                    'updated_by' => $auth->id,
                ];
            }
            $product->subCategories()->sync($pivotSub);

            $points = $request->input('points', []);
            $existingUuids = $product->points()
                ->withPivot('is_active')
                ->get()
                ->pluck('pivot.point_uuid')
                ->toArray();
            $incomingUuids = collect($points)->pluck('uuid')->toArray();

            $removedUuids = array_diff($existingUuids, $incomingUuids);

            foreach ($removedUuids as $removedUuid) {
                $product->points()->updateExistingPivot(
                    $removedUuid,
                    [
                        'is_active' => 0,
                        'updated_by' => $auth->id,
                    ]
                );
            }

            foreach ($points as $point) {

                $pointUuid = $point['uuid'];

                $existingPivot = $product->points()
                    ->withPivot('quantity', 'stocks_minimal')
                    ->wherePivot('point_uuid', $pointUuid)
                    ->first();


                if ($existingPivot) {

                    $product->points()->updateExistingPivot(
                        $pointUuid,
                        [
                            'quantity' => array_key_exists('quantity', $point)
                                ? $point['quantity']
                                : $existingPivot->pivot->quantity,

                            'stocks_minimal' => array_key_exists('stocks_minimal', $point)
                                ? $point['stocks_minimal']
                                : $existingPivot->pivot->stocks_minimal,

                            'is_active' => 1,
                            'updated_by' => $auth->id,
                        ]
                    );

                } else {
                    $product->points()->attach(
                        $pointUuid,
                        [
                            'quantity' => $point['quantity'] ?? 0,
                            'stocks_minimal' => $point['stocks_minimal'] ?? $validated['minimum_stock'],
                            'is_active' => 1,
                            'updated_by' => $auth->id,
                        ]
                    );
                }
            }
            if ($request->hasFile('image_file')) {
                $product->medias()->delete();

                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('products', 'public');

                $product->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Le produit '{$product->name}' a été mis à jour avec succès.",
                'product' => $product->load(['subCategories', 'points', 'medias']),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ProductController::destroy
     * @code STOCK
     * @permission_desc Supprimer un article
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

        $product = Product::findOrFail($uuid);

        $used = $product->purchaseOrderItems()->exists();

        if ($used) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de supprimer ce produit car il est déjà utilisé dans des commandes."
            ], 409);
        }

        // Supprime l'entrepôt
        $product->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "Produit a été supprimé avec succès."
        ]);
        //
    }

    /**
     * Display a listing of the resource.
     * @permission ProductController::export_products_by_points_uuid
     * @code STOCK
     * @permission_desc Imprimer l'inventaire des articles par entrepots en PDF
     */
    public function export_products_by_points_uuid(Request $request, ?string $warehouse_uuid = null)
    {
        try {

            if ($warehouse_uuid && $warehouse_uuid !== 'all') {

                $warehouse = Warehouse::where('uuid', $warehouse_uuid)->first();

                if (!$warehouse) {
                    return response()->json([
                        'message' => 'Entrepôt introuvable'
                    ], 404);
                }

                $product_points = ProductPoint::with([
                    'product.unitMeasure',
                    'product.category',
                    'point',
                    'creator',
                    'updater'
                ])
                    ->where('point_uuid', $warehouse_uuid)
                    ->get();

                if ($product_points->isEmpty()) {
                    return response()->json([
                        'message' => 'Aucun article trouvé pour cet entrepôt'
                    ], 404);
                }

                $fileName = 'INVENTAIRE-DE-L-ENTREPOT-'
                    . strtoupper($warehouse->name) . '-'
                    . now()->format('YmdHis') . '.pdf';

                $folderPath = 'storage/inventory-warehouse/' . $warehouse->uuid;
            }

            else {

                $product_points = ProductPoint::select(
                    'produit_uuid',
                    DB::raw('SUM(quantity) as quantity'),
                    DB::raw('MAX(stocks_minimal) as stocks_minimal')
                )
                    ->with([
                        'product.unitMeasure',
                        'product.category'
                    ])
                    ->groupBy('produit_uuid')
                    ->get();

                if ($product_points->isEmpty()) {
                    return response()->json([
                        'message' => 'Aucun article trouvé'
                    ], 404);
                }

                $warehouse = null;

                $fileName = 'INVENTAIRE-DE-TOUS-LES-ENTREPOTS-'
                    . now()->format('YmdHis') . '.pdf';

                $folderPath = 'storage/inventory-warehouse/all';
            }

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $filePath = $folderPath . '/' . $fileName;

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

            $pdfContent = file_get_contents($filePath);

            return response()->json([
                'base64'   => base64_encode($pdfContent),
                'filename' => $fileName,
                'url'      => $filePath,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission ProductController::print_inventory_by_day_and_warehouses
     * @code STOCK
     * @permission_desc Imprimer l'inventaire des articles par entrepots en PDF par période
     */
    public function print_inventory_by_day_and_warehouses(Request $request, ?string $warehouse_uuid = null)
    {
        try {
            $warehouse_uuid = $warehouse_uuid ?? $request->query('warehouse_uuid');
            $isAllWarehouses = ($warehouse_uuid === 'all');

            $end_date = $request->filled('end_date')
                ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
                : now()->endOfDay();

            if ($isAllWarehouses) {
                $warehouse_uuids = Warehouse::pluck('uuid')->toArray();
            } else {
                $warehouse_uuids = Warehouse::where('uuid', $warehouse_uuid)->pluck('uuid')->toArray();
                if (empty($warehouse_uuids)) {
                    return response()->json(['status' => 'error', 'message' => 'Entrepôt introuvable'], 404);
                }
            }

            $warehouses = Warehouse::whereIn('uuid', $warehouse_uuids)->get()->keyBy('uuid');

            // Tous les produits si 'all', sinon uniquement ceux liés à l'entrepôt sélectionné
            $productPointsQuery = ProductPoint::with(['product.unitMeasure', 'product.category', 'point'])
                ->whereHas('product');

            if (!$isAllWarehouses) {
                $productPointsQuery->whereIn('point_uuid', $warehouse_uuids);
            }

            $productPoints = $productPointsQuery->get();

            // Si 'all', on prend tous les mouvements (ou ceux des entrepôts existants)
            $whFilter = fn($query, string $column) => !$isAllWarehouses
                ? $query->whereIn($column, $warehouse_uuids)
                : (!empty($warehouse_uuids) ? $query->whereIn($column, $warehouse_uuids) : $query);

            $supplies = SupplyItem::whereHas('supply', function ($q) use ($end_date, $whFilter) {
                $whFilter($q->whereIn('status', [SupplyStatus::VALIDATED, SupplyStatus::PARTIALLY_VALIDATED])
                    ->where('supply_date', '<=', $end_date)
                    ->where('type', '!=', 'internal'), 'warehouse_uuid');
            })
                ->join('supplies', 'supply_items.supply_uuid', '=', 'supplies.uuid')
                ->select('supplies.warehouse_uuid', 'supply_items.product_uuid', DB::raw('SUM(supply_items.quantity_supplied) as total'))
                ->groupBy('supplies.warehouse_uuid', 'supply_items.product_uuid')
                ->get();

            $adjustments = StockAdjustmentItem::whereHas('adjustment', function ($q) use ($end_date, $whFilter) {
                $whFilter($q->where('status', StocksAdjustmentStatus::VALIDATED->value)
                    ->where('created_at', '<=', $end_date), 'warehouse_uuid');
            })
                ->join('stock_adjustments as adjustments', 'stock_adjustments_items.stock_adjustment_uuid', '=', 'adjustments.uuid')
                ->select('adjustments.warehouse_uuid', 'stock_adjustments_items.product_uuid', 'adjustments.action', DB::raw('SUM(stock_adjustments_items.quantity) as total'))
                ->groupBy('adjustments.warehouse_uuid', 'stock_adjustments_items.product_uuid', 'adjustments.action')
                ->get();

            $deductions = StockDeductionItem::whereHas('stockDeduction', function ($q) use ($end_date, $whFilter) {
                $whFilter($q->where('status', StocksDeductionsStatus::VALIDATED->value)
                    ->where('created_at', '<=', $end_date), 'warehouse_uuid');
            })
                ->join('stocks_deductions', 'stocks_deductions_items.stocks_deduction_uuid', '=', 'stocks_deductions.uuid')
                ->select('stocks_deductions.warehouse_uuid', 'stocks_deductions_items.product_uuid', DB::raw('SUM(stocks_deductions_items.quantity) as total'))
                ->groupBy('stocks_deductions.warehouse_uuid', 'stocks_deductions_items.product_uuid')
                ->get();

            $salesQuery = VirtualOrderMenuRestaurant::where('status', MenuOrderStatus::DELIVERED->value)
                ->where('created_at', '<=', $end_date);
            $whFilter($salesQuery, 'warehouse_uuid');
            $sales = $salesQuery
                ->select('warehouse_uuid', 'product_uuid', DB::raw('SUM(quantity_reserved) as total'))
                ->groupBy('warehouse_uuid', 'product_uuid')
                ->get();

            $complementsQuery = ComplementVirtualTemp::where('status', MenuOrderStatus::DELIVERED->value)
                ->where('created_at', '<=', $end_date);
            $whFilter($complementsQuery, 'warehouse_uuid');
            $complements = $complementsQuery
                ->select('warehouse_uuid', 'product_uuid', DB::raw('SUM(menu_quantity) as total'))
                ->groupBy('warehouse_uuid', 'product_uuid')
                ->get();

            $internalTransfersOut = SupplyItem::whereHas('supply', function ($q) use ($end_date, $whFilter) {
                $whFilter($q->whereIn('status', [SupplyStatus::VALIDATED, SupplyStatus::PARTIALLY_VALIDATED])
                    ->where('supply_date', '<=', $end_date)
                    ->where('type', 'internal'), 'warehouse_uuid');
            })
                ->join('supplies', 'supply_items.supply_uuid', '=', 'supplies.uuid')
                ->select('supplies.warehouse_uuid', 'supply_items.product_uuid', DB::raw('SUM(supply_items.quantity_supplied) as total'))
                ->groupBy('supplies.warehouse_uuid', 'supply_items.product_uuid')
                ->get();

            $computeQuantityForProduct = function (string $prodUuid, ?string $singleWhUuid) use (
                $warehouses, $supplies, $adjustments, $deductions, $sales, $complements, $internalTransfersOut
            ): float {
                $filterProd = fn($collection) => $collection->filter(function ($row) use ($prodUuid, $singleWhUuid) {
                    if ($row->product_uuid !== $prodUuid) {
                        return false;
                    }
                    return $singleWhUuid ? ($row->warehouse_uuid === $singleWhUuid) : true;
                });

                $prodSupplies = $filterProd($supplies);
                $prodTransOut = $filterProd($internalTransfersOut);
                $prodDeducted = (float) $filterProd($deductions)->sum('total');
                $prodSales = (float) $filterProd($sales)->sum('total');
                $prodComplements = (float) $filterProd($complements)->sum('total');

                $prodAdjusts = $filterProd($adjustments);
                $prodAdjPlus = (float) $prodAdjusts->where('action', StockAdjustmentAction::AJUSTEMENT_PLUS->value)->sum('total');
                $prodAdjMinus = (float) $prodAdjusts->where('action', StockAdjustmentAction::AJUSTEMENT_MOINS->value)->sum('total');
                $prodAvaries = (float) $prodAdjusts->where('action', StockAdjustmentAction::AVARIE->value)->sum('total');

                $baseIn = 0.0;
                foreach ($prodSupplies as $row) {
                    $wh = $warehouses->get($row->warehouse_uuid);
                    if ((bool) ($wh->is_primary ?? false)) {
                        $baseIn += (float) $row->total;
                    }
                }
                foreach ($prodTransOut as $row) {
                    $wh = $warehouses->get($row->warehouse_uuid);
                    if (!(bool) ($wh->is_primary ?? false)) {
                        $baseIn += (float) $row->total;
                    }
                }

                $totalIn = $baseIn + $prodAdjPlus;
                $totalOut = $prodAdjMinus + $prodAvaries + $prodDeducted + $prodSales + $prodComplements;

                return max(0, $totalIn - $totalOut);
            };

            $sortByNameAsc = fn($collection) => $collection->sortBy(
                fn($row) => mb_strtolower(trim($row->product->name ?? '')),
                SORT_NATURAL | SORT_FLAG_CASE
            )->values();

            if ($isAllWarehouses) {
                $computedPoints = $sortByNameAsc(
                    $productPoints
                        ->groupBy('produit_uuid')
                        ->map(function ($groupItems, $prodUuid) use ($computeQuantityForProduct) {
                            $firstItem = $groupItems->first();
                            $finalQuantity = $computeQuantityForProduct($prodUuid, null);

                            return (object) [
                                'point_uuid'   => null,
                                'warehouse'    => null,
                                'produit_uuid' => $prodUuid,
                                'product'      => $firstItem->product,
                                'quantity'     => $finalQuantity,
                                'point_item'   => $firstItem,
                            ];
                        })
                );

                $inventoriesByWarehouse = collect();
            } else {
                $computedPoints = $sortByNameAsc(
                    $productPoints->map(function ($pointItem) use ($warehouses, $computeQuantityForProduct) {
                        $whUuid = $pointItem->point_uuid;
                        $prodUuid = $pointItem->produit_uuid;
                        $wh = $warehouses->get($whUuid);

                        return (object) [
                            'point_uuid'   => $whUuid,
                            'warehouse'    => $wh ?? $pointItem->point,
                            'produit_uuid' => $prodUuid,
                            'product'      => $pointItem->product,
                            'quantity'     => $computeQuantityForProduct($prodUuid, $whUuid),
                            'point_item'   => $pointItem,
                        ];
                    })
                );

                $inventoriesByWarehouse = $computedPoints
                    ->groupBy('point_uuid')
                    ->map(function ($items, $whUuid) use ($warehouses, $sortByNameAsc) {
                        $sortedItems = $sortByNameAsc($items);
                        return (object) [
                            'warehouse'      => $warehouses->get($whUuid),
                            'product_points' => $sortedItems,
                            'total_quantity' => $sortedItems->sum('quantity'),
                        ];
                    })
                    ->values();
            }

            $fileName = $isAllWarehouses
                ? 'INVENTAIRE-TOUS-PRODUITS-' . now()->format('YmdHis') . '.pdf'
                : 'INVENTAIRE-ENTREPOT-' . strtoupper($warehouses->first()->name ?? 'ENTREPOT') . '-' . now()->format('YmdHis') . '.pdf';

            $folderPath = $isAllWarehouses
                ? 'storage/inventory_warehouses_day'
                : 'storage/inventory_warehouse_day/' . $warehouses->first()->uuid;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $filePath = $folderPath . '/' . $fileName;

            $data = [
                'is_all_warehouses'        => $isAllWarehouses,
                'warehouse'                => $isAllWarehouses ? null : $warehouses->first(),
                'inventories_by_warehouse' => $inventoriesByWarehouse,
                'product_points'           => $computedPoints,
                'end_date'                 => $end_date,
            ];

            save_browser_shot_pdf(
                view: 'pdfs.inventory_warehouse_day.inventory_warehouse_day',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [5, 5, 5, 5],
                footer: 'pdfs.reports.factures.footer'
            );

            return response()->json([
                'status'   => 'success',
                'base64'   => base64_encode(file_get_contents($filePath)),
                'filename' => $fileName,
                'url'      => $filePath,
                'data'     => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération de l\'inventaire',
                'details' => $e->getMessage(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }


    public function getLastSellPriceForProduct($product_uuid)
    {
        $item = SupplyItem::where('product_uuid', $product_uuid)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->with('product')
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun enregistrement trouvé pour ce produit.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product_uuid' => $item->product_uuid,
                'product_name' => $item->product?->name,
                'sell_price'   => $item->sell_price,
                'date'         => $item->created_at,
            ]
        ]);
    }



























}

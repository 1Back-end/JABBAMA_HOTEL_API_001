<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrdersStatus;
use App\Enums\StockAdjustmentAction;
use App\Enums\SupplyStatus;
use App\Models\PdfDocument;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Supply;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @permission_category Gestion des statistiques
 */
class StatisticsController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission StatisticsController::topConsumedProducts
     * @permission_desc Statistiques sur les articles les plus consommées
     */
    public function topConsumedProducts(Request $request)
    {
        $start_date     = $request->input('start_date');
        $end_date       = $request->input('end_date');
        $warehouse_uuid = $request->input('warehouse_uuid');

        $query = DB::table('supply_items as si')
            ->join('supplies as s', 'si.supply_uuid', '=', 's.uuid')
            ->join('purchase_orders as po', 's.purchase_order_uuid', '=', 'po.uuid')
            ->join('produits as p', 'si.product_uuid', '=', 'p.uuid')
            ->join('warehouses as w', 'w.uuid', '=', 'po.warehouse_from') // Entrepôt source de la commande
            ->selectRaw('
            p.uuid,
            p.code,
            p.name,
            w.name as warehouse,
            COUNT(DISTINCT s.uuid) as frequency
        ')
            ->groupBy('p.uuid', 'p.code', 'p.name', 'w.name')
            ->orderByDesc('frequency');

        // Filtrer par dates si fournies
        if ($start_date && $end_date) {
            $query->whereBetween('s.supply_date', [$start_date, $end_date]);
        }

        // Filtrer par entrepôt si fourni
        if ($warehouse_uuid) {
            // Prendre en compte l’entrepôt principal ET les approvisionnements vers l’extérieur
            $query->where(function($q) use ($warehouse_uuid) {
                $q->where('po.warehouse_from', $warehouse_uuid)
                    ->orWhere('po.warehouse_to', $warehouse_uuid);
            });
        }

        $data = $query->get();

        return response()->json($data);
    }


    public function get_statistics_by_products(Request $request)
    {
        // Récupérer le total des articles ajustés
        $totalItems = StockAdjustmentItem::count();

        if ($totalItems === 0) {
            return response()->json([
                'message' => 'Aucun ajustement trouvé',
                'data' => []
            ]);
        }

        // Récupérer le nombre d'articles par type d'action
        $stats = StockAdjustmentItem::select('adjustments.action', DB::raw('COUNT(*) as total'))
            ->join('stock_adjustments as adjustments', 'adjustments.uuid', '=', 'stock_adjustments_items.stock_adjustment_uuid')
            ->groupBy('adjustments.action')
            ->get();

        // Calculer le pourcentage par type
        $result = [];
        foreach ($stats as $stat) {
            $result[] = [
                'action' => StockAdjustmentAction::LABEL($stat->action),
                'total' => $stat->total,
                'percentage' => round(($stat->total / $totalItems) * 100, 2) . '%',
            ];
        }

        // Ajouter éventuellement les actions qui n'ont aucun item (0%)
        foreach (StockAdjustmentAction::TO_ARRAY() as $value => $label) {
            if (!collect($result)->pluck('action')->contains(StockAdjustmentAction::LABEL($value))) {
                $result[] = [
                    'action' => StockAdjustmentAction::LABEL($value),
                    'total' => 0,
                    'percentage' => '0%'
                ];
            }
        }

        return response()->json([
            'total_items' => $totalItems,
            'data' => $result
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::statisticsByProduct
     * @permission_desc Statistiques sur les articles(Variation prix Unitaire,Quantitée)
     */
    public function statisticsByProduct(Request $request, string $productUuid)
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : now()->subMonth()->startOfDay();
        $endDate   = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : now()->endOfDay();
        $warehouseUuid = $request->warehouse_uuid ?? null;
        $action = $request->action ?? 'quantity_variation'; // par défaut quantity_variation

        $productName = DB::table('produits')->where('uuid', $productUuid)->value('name') ?? 'Inconnu';

        // --- AVARIES_RATE et PRICE_VARIATION restent inchangés ---
        if ($action === 'avaries_rate') {
            $totalQuantity = DB::table('supply_items as si')
                ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                ->join('purchase_orders as po', 'po.uuid', '=', 's.purchase_order_uuid')
                ->where('si.product_uuid', $productUuid)
                ->whereBetween('s.supply_date', [$startDate, $endDate])
                ->when($warehouseUuid, fn($q) => $q->where('s.warehouse_uuid', $warehouseUuid)->where('po.type', 'internal'))
                ->when(!$warehouseUuid, fn($q) => $q->where('po.type', 'external'))
                ->sum('si.quantity_supplied');

            $totalAvarie = DB::table('stock_adjustments_items as sai')
                ->join('stock_adjustments as sa', 'sa.uuid', '=', 'sai.stock_adjustment_uuid')
                ->where('sai.product_uuid', $productUuid)
                ->where('sa.action', StockAdjustmentAction::AVARIE->value)
                ->whereBetween('sa.created_at', [$startDate, $endDate])
                ->when($warehouseUuid, fn($q) => $q->where('sa.warehouse_uuid', $warehouseUuid))
                ->sum('sai.quantity');

            $avarieRate = $totalQuantity > 0 ? ($totalAvarie / $totalQuantity) * 100 : 0;

            return response()->json([
                'product'        => $productName,
                'scale'          => 'percentage',
                'total_quantity' => $totalQuantity,
                'total_avarie'   => $totalAvarie,
                'avarie_rate'    => round($avarieRate, 2),
            ]);
        }

        if ($action === 'price_variation') {
            $itemsQuery = DB::table('supply_items as si')
                ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                ->join('purchase_orders as po', 'po.uuid', '=', 's.purchase_order_uuid')
                ->where('si.product_uuid', $productUuid)
                ->whereBetween('s.supply_date', [$startDate, $endDate]);

            if ($warehouseUuid) {
                $itemsQuery
                    ->where('po.type', 'internal')
                    ->where(function ($q) use ($warehouseUuid) {
                        $q->where('po.warehouse_from', $warehouseUuid)
                            ->orWhere('po.warehouse_to', $warehouseUuid);
                    });
            } else {
                $itemsQuery->where('po.type', 'external');
            }

            $itemsQuery->select('s.supply_date', DB::raw("IF(po.type='internal', si.sell_price, si.unit_price) as value"));

            $items = $itemsQuery->get();

            $resultPoints = $items->groupBy(function ($item) {
                return Carbon::parse($item->supply_date)->format('Y-m-d');
            })->map(function ($dayItems, $day) {
                $total = collect($dayItems)->sum('value');
                return [
                    'period' => $day,
                    'value'  => number_format($total, 3, '.', '')
                ];
            })->values();

            return response()->json([
                'product' => $productName,
                'scale'   => 'day',
                'points'  => $resultPoints,
            ]);
        }

        if ($action === 'quantity_variation') {
            $items = StockAdjustmentItem::query()
                ->select('stock_adjustments_items.*', 'sa.created_at as adjustment_date')
                ->join('stock_adjustments as sa', 'sa.uuid', '=', 'stock_adjustments_items.stock_adjustment_uuid')
                ->where('stock_adjustments_items.product_uuid', $productUuid)
                ->where('sa.action', StockAdjustmentAction::DEDUCTION->value) // uniquement consommations
                ->whereBetween('sa.created_at', [$startDate, $endDate])
                ->when($warehouseUuid && $warehouseUuid !== 'tous', function ($q) use ($warehouseUuid) {
                    $q->where('sa.warehouse_uuid', $warehouseUuid);
                })
                ->get();

            $pointsByDate = $items->groupBy(function ($item) {
                return Carbon::parse($item->adjustment_date)->format('Y-m-d');
            })->map(function ($dayItems, $day) {
                return [
                    'period' => $day,
                    'value'  => $dayItems->sum('quantity')
                ];
            })->values();

            return response()->json([
                'product' => $productName,
                'scale'   => 'day',
                'points'  => $pointsByDate,
            ]);
        }

        return response()->json(['error' => 'Action non supportée'], 400);
    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::suppliesOrders
     * @permission_desc Statistiques sur le journal des commandes
     */
    public function suppliesOrders(Request $request)
    {
        try {
            // 🔹 Validation des dates et du statut optionnel
            $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date',
                'status'     => 'nullable|string',
            ]);

            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end   = \Illuminate\Support\Carbon::parse($request->end_date)->endOfDay();
            $statusFilter = $request->status;

            // 🔹 Construction de la requête
            $query = PurchaseOrder::whereBetween('created_at', [$start, $end]);

            // Filtre par statut si différent de "all"
            if ($statusFilter && strtolower($statusFilter) !== 'all') {
                $query->where('status', $statusFilter);
            }

            $orders = $query->get();

            // 🔹 Groupement par statut
            $totals = $orders->groupBy('status')->map(function ($group, $status) {
                return [
                    'total'      => $group->count(),
                    'types'      => $group->pluck('type')->unique()->values(),
                    'references' => $group->pluck('reference')->values(),
                    'label'      => PurchaseOrdersStatus::safeLabel($status),
                ];
            });

            // 🔹 Réponse OK
            return response()->json([
                'status'  => 'success',
                'data'    => $totals,
                'summary' => [
                    'total_orders' => $orders->count(),
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne lors du chargement des commandes',
                'error'   => $e->getMessage(), // retire en prod si besoin
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission StatisticsController::suppliesJournal
     * @permission_desc Statistiques sur le journal des approvisionnements
     */
    public function suppliesJournal(Request $request)
    {
        try {
            // 🔹 Validation
            $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date',
                'status'     => 'nullable|string', // "all" ou statut spécifique
            ]);

            $start = Carbon::parse($request->start_date)->startOfDay();
            $end   = Carbon::parse($request->end_date)->endOfDay();
            $statusFilter = $request->status ?? 'all';

            // 🔹 Récupération des approvisionnements sur la période
            $suppliesQuery = Supply::whereBetween('created_at', [$start, $end]);

            // 🔹 Filtrer par statut si ce n'est pas "all"
            if ($statusFilter !== 'all') {
                $suppliesQuery->where('status', $statusFilter);
            }

            $supplies = $suppliesQuery->get();

            // 🔹 Groupement par statut
            $totalsByStatus = $supplies->groupBy('status')->map(function ($group, $status) {
                return [
                    'total'      => $group->count(),
                    'references' => $group->pluck('reference')->values(),
                    'label'      => SupplyStatus::safeLabel($status),
                ];
            })->toArray();

            return response()->json([
                'status' => 'success',
                'data'   => $totalsByStatus,
                'summary' => [
                    'total_supplies' => $supplies->count(),
                ]
            ]);

        } catch (\Exception $e) {
            // 🔹 Gestion des erreurs
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du chargement des approvisionnements : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission StatisticsController::StockAdjustmentsJournal
     * @permission_desc Statistiques sur le journal des régularisations de stocks
     */
    public function StockAdjustmentsJournal(Request $request)
    {
        try {
            // 🔹 Validation
            $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date',
                'action'     => 'nullable|string', // 'all' ou action spécifique
            ]);

            $start = \Carbon\Carbon::parse($request->start_date)->startOfDay();
            $end   = \Carbon\Carbon::parse($request->end_date)->endOfDay();
            $actionFilter = $request->action;

            // 🔹 Requête de base
            $query = StockAdjustment::whereBetween('created_at', [$start, $end]);

            // 🔹 Filtrer par action si ce n'est pas "all"
            if ($actionFilter && strtolower($actionFilter) !== 'all') {
                $query->where('action', $actionFilter);
            }

            $adjustments = $query->get();

            // 🔹 Groupement par action
            $data = $adjustments->groupBy('action')->map(function ($group, $action) {
                return [
                    'total'      => $group->count(),
                    'action'     => $action,
                    'label'      => \App\Enums\StockAdjustmentAction::LABEL((int) $action),
                    'references' => $group->pluck('reference')->values(),
                    'statuses'   => $group->pluck('status')->unique()->values(),
                ];
            });

            return response()->json([
                'status'  => 'success',
                'data'    => $data,
                'summary' => [
                    'total_adjustments' => $adjustments->count(),
                    'actions'           => \App\Enums\StockAdjustmentAction::TO_ARRAY(),
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne lors du chargement des régularisations de stock',
                'error'   => $e->getMessage(), // à masquer en prod
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission StatisticsController::print_suppliesOrders
     * @permission_desc Imprimer le journal des commandes en PDF
     */
    public function print_suppliesOrders(Request $request)
    {
        try {
            $auth = auth()->user();

            // 🔹 Validation
            $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date',
                'status'     => 'nullable|string',
            ]);

            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end   = \Illuminate\Support\Carbon::parse($request->end_date)->endOfDay();
            $statusFilter = $request->status;

            DB::beginTransaction();

            // 🔹 Récupérer toutes les commandes selon dates et statut
            $query = PurchaseOrder::with([
                'items.product',
                'creator',
                'updater',
                'approver',
                'children',
                'parent',
                'supplier',
                'warehouseTo',
                'warehouseFrom',
            ])->whereBetween('created_at', [$start, $end]);

            if ($statusFilter && strtolower($statusFilter) !== 'all') {
                $query->where('status', $statusFilter);
            }

            $orders = $query->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Aucune commande trouvée pour cette période ou ce statut.',
                ], 404);
            }

            // 🔹 Définir le chemin et le nom du PDF
            $fileName   = strtoupper('LISTE-DES-COMMANDES-' . $start->format('dmy') . '-' . $end->format('dmy') . '.pdf');
            $folderPath = 'storage/orders-list';
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'orders'     => $orders,
                'start_date' => $start->format('d/m/Y'),
                'end_date'   => $end->format('d/m/Y'),
            ];

            $footer = 'pdfs.reports.factures.footer';

            // 🔹 Générer le PDF (utiliser ton helper)
            save_browser_shot_pdf(
                view: 'pdfs.orders-list.orders-list',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
            );

            // 🔹 Créer ou mettre à jour l'enregistrement PDF
            $pdf = PdfDocument::updateOrCreate(
                [
                    'name' => 'ORDERS-LIST',
                ],
                [
                    'disk'      => 'public',
                    'path'      => $filePath,
                    'filename'  => $fileName,
                    'mimetype'  => 'application/pdf',
                    'extension' => 'pdf',
                    'updated_by'=> $auth->id,
                    'created_by'=> $auth->id,
                ]
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            DB::commit();

            return response()->json([
                'status'   => 'success',
                'base64'   => base64_encode($pdfContent),
                'filename' => $fileName,
                'url'      => $filePath,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error("Erreur génération PDF commandes : " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Erreur lors de la génération du fichier PDF.",
                'details' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::print_suppliesJournal
     * @permission_desc Imprimer le journal des approvisionnements en PDF
     */
    public function print_suppliesJournal(Request $request)
    {
        try {
            $auth = auth()->user();

            // 🔹 Validation
            $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date',
                'status'     => 'nullable|string',
            ]);

            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end   = \Illuminate\Support\Carbon::parse($request->end_date)->endOfDay();
            $statusFilter = $request->status;

            DB::beginTransaction();

            // 🔹 Récupérer tous les approvisionnements selon dates et statut
            $query = Supply::with([
                'items.product',
                'items.supplier',
                'purchaseOrder.items',
                'purchaseOrder.warehouseTo.natures',
                'purchaseOrder.warehouseFrom.natures',
                'creator',
                'updater',
                'partially_validated',
                'rejector',
                'validator',
                'medias',
                'warehouse',
            ])->whereBetween('created_at', [$start, $end]);

            if ($statusFilter && strtolower($statusFilter) !== 'all') {
                $query->where('status', $statusFilter);
            }

            $supplies = $query->get();

            if ($supplies->isEmpty()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Aucun approvisionnement trouvé pour cette période ou ce statut.',
                ], 404);
            }

            // 🔹 Définir le chemin et le nom du PDF
            $folderPath = 'storage/supply-list';
            $fileName   = strtoupper('LISTE-DES-APPROVISIONNEMENTS-' . $start->format('dmy') . '-' . $end->format('dmy') . '.pdf');
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'supplies'   => $supplies,
                'start_date' => $start->format('d/m/Y'),
                'end_date'   => $end->format('d/m/Y'),
            ];

            $footer = 'pdfs.reports.factures.footer';

            // 🔹 Générer le PDF
            save_browser_shot_pdf(
                view: 'pdfs.supply-list.supply-list',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
            );

            if (!file_exists($filePath)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Le fichier PDF n'a pas été généré."
                ], 500);
            }

            // 🔹 Enregistrer ou mettre à jour le document PDF
            $pdf = PdfDocument::updateOrCreate(
                [
                    'name' => 'SUPPLY-LIST',
                ],
                [
                    'disk'      => 'public',
                    'path'      => $filePath,
                    'filename'  => $fileName,
                    'mimetype'  => 'application/pdf',
                    'extension' => 'pdf',
                    'updated_by'=> $auth->id,
                    'created_by'=> $auth->id,
                ]
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            DB::commit();

            return response()->json([
                'status'   => 'success',
                'message'  => 'Rapport généré avec succès.',
                'data'     => $supplies,
                'base64'   => $base64,
                'url'      => asset($filePath),
                'filename' => $fileName,
                'document' => $pdf,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error("Erreur génération PDF approvisionnements : " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Erreur lors de la génération du fichier PDF.",
                'details' => $e->getMessage()
            ], 500);
        }
    }






































    //
}

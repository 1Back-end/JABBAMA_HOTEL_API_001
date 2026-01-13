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
     * @permission StatisticsController::menu_statistics
     * @permission_desc Afficher le menu des rapports de stocks
     */
    public function menu_statistics(Request $request)
    {

    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::suppliesOrders
     * @permission_desc Statistiques sur le journal des commandes
     */
    public function suppliesOrders(Request $request)
    {
        try {
            // 🔹 Validation (dates optionnelles)
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date'   => 'nullable|date',
                'status'     => 'nullable|string',
            ]);

            // 🔹 Dates par défaut = aujourd’hui
            $start = $request->start_date
                ? \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay()
                : now()->startOfDay();

            $end = $request->end_date
                ? \Illuminate\Support\Carbon::parse($request->end_date)->endOfDay()
                : now()->endOfDay();

            $statusFilter = $request->status;

            // 🔹 Requête
            $query = PurchaseOrder::whereBetween('created_at', [$start, $end]);

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

            return response()->json([
                'status'  => 'success',
                'data'    => $totals,
                'summary' => [
                    'total_orders' => $orders->count(),
                    'from' => $start->toDateString(),
                    'to'   => $end->toDateString(),
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
                'message' => 'Erreur interne',
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
            // 🔹 Validation (dates optionnelles)
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date'   => 'nullable|date',
                'status'     => 'nullable|string', // "all" ou statut spécifique
            ]);

            // 🔹 Dates par défaut = aujourd’hui
            $start = $request->start_date
                ? Carbon::parse($request->start_date)->startOfDay()
                : now()->startOfDay();

            $end = $request->end_date
                ? Carbon::parse($request->end_date)->endOfDay()
                : now()->endOfDay();

            $statusFilter = $request->status ?? 'all';

            // 🔹 Requête approvisionnements
            $suppliesQuery = Supply::whereBetween('created_at', [$start, $end]);

            if (strtolower($statusFilter) !== 'all') {
                $suppliesQuery->where('status', $statusFilter);
            }

            $supplies = $suppliesQuery->get();

            // 🔹 Groupement par statut
            $totalsByStatus = $supplies
                ->groupBy('status')
                ->map(function ($group, $status) {
                    return [
                        'total'      => $group->count(),
                        'references' => $group->pluck('reference')->values(),
                        'label'      => SupplyStatus::safeLabel($status),
                    ];
                });

            return response()->json([
                'status'  => 'success',
                'data'    => $totalsByStatus,
                'summary' => [
                    'total_supplies' => $supplies->count(),
                    'from' => $start->toDateString(),
                    'to'   => $end->toDateString(),
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du chargement des approvisionnements',
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
            // 🔹 Validation (dates optionnelles)
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date'   => 'nullable|date',
                'action'     => 'nullable|string', // 'all' ou action spécifique
            ]);

            // 🔹 Dates par défaut = aujourd’hui
            $start = $request->start_date
                ? \Carbon\Carbon::parse($request->start_date)->startOfDay()
                : now()->startOfDay();

            $end = $request->end_date
                ? \Carbon\Carbon::parse($request->end_date)->endOfDay()
                : now()->endOfDay();

            $actionFilter = $request->action ?? 'all';

            // 🔹 Requête de base
            $query = StockAdjustment::whereBetween('created_at', [$start, $end]);

            if (strtolower($actionFilter) !== 'all') {
                $query->where('action', (int) $actionFilter); // forcer l'entier
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
                    'from'    => $start->toDateString(),
                    'to'      => $end->toDateString(),
                    'actions' => \App\Enums\StockAdjustmentAction::TO_ARRAY(),
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


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::get_statictic_by_variation_supply_price
     * @permission_desc Statistiques sur la variation sur les prix unitaires
     */
    public function get_statictic_by_variation_supply_price(Request $request, string $productUuid)
    {
        // 🔹 Validation
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        // 🔹 Dates par défaut
        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfDay();

        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->endOfDay();

        // 🔹 Nom du produit
        $productName = DB::table('produits')
            ->where('uuid', $productUuid)
            ->value('name') ?? 'Inconnu';

        // 🔹 Récupérer les supply_items des commandes internes
        $itemsQuery = DB::table('supply_items as si')
            ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
            ->join('purchase_orders as po', 'po.uuid', '=', 's.purchase_order_uuid')
            ->where('si.product_uuid', $productUuid)
            ->where('s.status', [SupplyStatus::VALIDATED, SupplyStatus::PARTIALLY_VALIDATED])
            ->whereBetween('s.supply_date', [$startDate, $endDate]);

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
        })
            ->sortBy('period')   // 🔹 TRI croissant par date
            ->values();          // 🔹 Réindexe les clés

        return response()->json([
            'product' => $productName,
            'scale'   => 'day',
            'from'    => $startDate->toDateString(),
            'to'      => $endDate->toDateString(),
            'points'  => $resultPoints,
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::get_statitics_by_variation_quantity
     * @permission_desc Statistiques sur la variation sur les quantités
     */
    public function get_statitics_by_variation_quantity(Request $request, string $productUuid)
    {
        $request->validate([
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'warehouse_uuid' => 'nullable|exists:warehouses,uuid',
        ]);

        // 📅 Période
        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfDay();

        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : $endDate->copy()->subMonth()->startOfDay();

        // 🏷️ Produit
        $productName = DB::table('produits')
            ->where('uuid', $productUuid)
            ->value('name') ?? 'Inconnu';

        $warehouseUuid = $request->warehouse_uuid;
        $warehouseName = 'Tous';
        $points = collect();

        /**
         * ==========================================
         * 🔹 CAS 1 : AUCUN ENTREPÔT → TOUS
         * ==========================================
         */
        if (!$warehouseUuid) {

            // Approvisionnements (tous entrepôts)
            $supplies = DB::table('supply_items as si')
                ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                ->where('si.product_uuid', $productUuid)
                ->whereIn('s.status', [SupplyStatus::VALIDATED, SupplyStatus::PARTIALLY_VALIDATED])
                ->whereBetween('s.supply_date', [$startDate, $endDate])
                ->select(
                    DB::raw('DATE(s.supply_date) as day'),
                    DB::raw('SUM(si.quantity_supplied) as qty')
                )
                ->groupBy('day')
                ->get();

            // Consommations (tous entrepôts)
            $deductions = DB::table('stocks_deductions_items as sdi')
                ->join('stocks_deductions as sd', 'sd.uuid', '=', 'sdi.stocks_deduction_uuid')
                ->where('sdi.product_uuid', $productUuid)
                ->where('sd.status', 'validated')
                ->whereBetween('sd.created_at', [$startDate, $endDate])
                ->select(
                    DB::raw('DATE(sd.created_at) as day'),
                    DB::raw('SUM(sdi.quantity) as qty')
                )
                ->groupBy('day')
                ->get();

            // Fusion + somme par jour
            $points = $supplies
                ->merge($deductions)
                ->groupBy('day')
                ->map(fn ($rows, $day) => [
                    'period' => $day,
                    'value'  => $rows->sum('qty'),
                ])
                ->sortBy('period')
                ->values();
        }

        /**
         * ==========================================
         * 🔹 CAS 2 : ENTREPÔT SPÉCIFIQUE
         * ==========================================
         */
        else {
            $warehouse = DB::table('warehouses')->where('uuid', $warehouseUuid)->first();
            $warehouseName = $warehouse->name ?? 'Tous';

            // 🟢 Entrepôt principal → approvisionnements
            if ($warehouse && $warehouse->is_primary) {

                $items = DB::table('supply_items as si')
                    ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                    ->where('si.product_uuid', $productUuid)
                    ->where('s.warehouse_uuid', $warehouseUuid)
                    ->whereIn('s.status', [SupplyStatus::VALIDATED, SupplyStatus::PARTIALLY_VALIDATED])
                    ->whereBetween('s.supply_date', [$startDate, $endDate])
                    ->select(
                        DB::raw('DATE(s.supply_date) as day'),
                        DB::raw('SUM(si.quantity_supplied) as total')
                    )
                    ->groupBy('day')
                    ->orderBy('day')
                    ->get();

                $points = $items->map(fn ($item) => [
                    'period' => $item->day,
                    'value'  => (int) $item->total,
                ]);
            }

            // 🔴 Entrepôt secondaire → consommations
            else {
                $items = DB::table('stocks_deductions_items as sdi')
                    ->join('stocks_deductions as sd', 'sd.uuid', '=', 'sdi.stocks_deduction_uuid')
                    ->where('sdi.product_uuid', $productUuid)
                    ->where('sd.warehouse_uuid', $warehouseUuid)
                    ->where('sd.status', 'validated')
                    ->whereBetween('sd.created_at', [$startDate, $endDate])
                    ->select(
                        DB::raw('DATE(sd.created_at) as day'),
                        DB::raw('SUM(sdi.quantity) as total')
                    )
                    ->groupBy('day')
                    ->orderBy('day')
                    ->get();

                $points = $items->map(fn ($item) => [
                    'period' => $item->day,
                    'value'  => (int) $item->total,
                ]);
            }
        }

        return response()->json([
            'product'   => $productName,
            'warehouse' => $warehouseName,
            'scale'     => 'day',
            'from'      => $startDate->toDateString(),
            'to'        => $endDate->toDateString(),
            'points'    => $points,
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::get_statistics_by_avaries_products
     * @permission_desc Statistiques sur le taux d'avaries des articles
     */
    public function get_statistics_by_avaries_products(Request $request, string $productUuid)
    {
        $request->validate([
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'warehouse_uuid' => 'nullable|exists:warehouses,uuid',
        ]);

        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfDay();

        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : $endDate->copy()->subMonth()->startOfDay();

        // 🔹 Nom du produit
        $productName = DB::table('produits')
            ->where('uuid', $productUuid)
            ->value('name') ?? 'Inconnu';

        $warehouseUuid = $request->warehouse_uuid;

        // 🔹 Nom de l'entrepôt si fourni
        $warehouseName = $warehouseUuid
            ? DB::table('warehouses')->where('uuid', $warehouseUuid)->value('name')
            : 'Tous';

        // 🔹 Total approvisionné pour le produit
        $totalSupplied = DB::table('supply_items as si')
            ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
            ->when($warehouseUuid, fn($q) => $q->where('s.warehouse_uuid', $warehouseUuid))
            ->where('si.product_uuid', $productUuid)
            ->whereBetween('s.supply_date', [$startDate, $endDate])
            ->sum('si.quantity_supplied');

        // 🔹 Total des avaries (ajustements de type AVARIE)
        $totalAvaries = DB::table('stock_adjustments_items as sai')
            ->join('stock_adjustments as sa', 'sa.uuid', '=', 'sai.stock_adjustment_uuid')
            ->when($warehouseUuid, fn($q) => $q->where('sa.warehouse_uuid', $warehouseUuid))
            ->where('sai.product_uuid', $productUuid)
            ->where('sa.action', 1) // uniquement AVARIE
            ->whereBetween('sa.created_at', [$startDate, $endDate])
            ->sum('sai.quantity');

        // 🔹 Calcul du pourcentage d'avaries
        $percentAvaries = $totalSupplied > 0
            ? round(($totalAvaries / $totalSupplied) * 100, 2)
            : 0;

        // 🔹 Préparer les données pour le camembert
        $data = [
            ['label' => 'Avaries', 'value' => $percentAvaries],
            ['label' => 'Quantité intacte', 'value' => 100 - $percentAvaries],
        ];

        return response()->json([
            'product'   => $productName,
            'warehouse' => $warehouseName,
            'from'      => $startDate->toDateString(),
            'to'        => $endDate->toDateString(),
            'data'      => $data,
        ]);
    }















































    //
}

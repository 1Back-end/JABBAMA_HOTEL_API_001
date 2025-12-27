<?php

namespace App\Http\Controllers;

use App\Enums\StockAdjustmentAction;
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
     * Display statistics by product (quantities, prices, avaries)
     * @permission StatisticsController::get_statistics_by_product
     * @permission_desc Statistiques sur la variation des quantités, prix unitaires et avaries des articles
     */
    public function get_statistics_by_product(Request $request, string $uuid)
    {
        // Dates de filtrage
        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = Carbon::parse($request->end_date)->endOfDay();

        $action    = $request->input('action');
        $warehouse = $request->input('warehouse_uuid');

        // Type d’approvisionnement
        $supplyType = $warehouse ? 'internal' : 'external';

        // -------- BASE QUERY APPROVISIONNEMENTS --------
        $baseSupplyQuery = DB::table('supply_items')
            ->join('supplies', 'supplies.uuid', '=', 'supply_items.supply_uuid')
            ->where('supply_items.product_uuid', $uuid)
            ->where('supplies.type', $supplyType)
            ->whereBetween('supplies.supply_date', [$start, $end])
            ->when($warehouse, fn ($q) =>
            $q->where('supplies.warehouse_uuid', $warehouse)
            );

        // ------------------ VARIATION DE PRIX ------------------
        if ($action === 'variation_price') {
            $data = $baseSupplyQuery
                ->selectRaw("
                DATE(supplies.supply_date) as period,
                supply_items.unit_price as value
            ")
                ->orderBy('period')
                ->get();

            return response()->json([
                'period' => 'dynamic', // On pourra gérer l'affichage dynamique côté Angular
                'data'   => $data
            ]);
        }

        // ------------------ VARIATION DES QUANTITÉS ------------------
        if ($action === 'variation_quantity') {
            // Ici on prend en compte l'entrepôt via purchase_orders.warehouse_from
            $data = DB::table('purchase_orders as po')
                ->join('purchase_order_items as poi', 'poi.purchase_order_uuid', '=', 'po.uuid')
                ->where('poi.product_uuid', $uuid)
                ->whereBetween('po.created_at', [$start, $end])
                ->when($warehouse, fn ($q) =>
                $q->where('po.warehouse_from', $warehouse)
                )
                ->selectRaw("
                DATE(po.created_at) as period,
                SUM(poi.quantity) as value
            ")
                ->groupBy('period')
                ->orderBy('period')
                ->get();

            return response()->json([
                'period' => 'dynamic',
                'data'   => $data
            ]);
        }

        // ------------------ TAUX D’AVARIES ------------------
        if ($action === 'avarie') {
            $query = DB::table('stock_adjustments_items as sai')
                ->join('stock_adjustments as sa', 'sa.uuid', '=', 'sai.stock_adjustment_uuid')
                ->where('sa.action', 1) // AVARIE
                ->where('sai.product_uuid', $uuid)
                ->when($start && $end, fn($q) => $q->whereBetween('sa.created_at', [$start, $end]));

            if ($warehouse) {
                // Cas avec entrepôt sélectionné
                $query->where('sa.warehouse_uuid', $warehouse)
                    ->join('warehouses as w', 'w.uuid', '=', 'sa.warehouse_uuid')
                    ->selectRaw('w.name as warehouse_name, SUM(sai.quantity) as total_avarie')
                    ->groupBy('w.name');
            } else {
                // Cas global, pas d'entrepôt
                $query->selectRaw('SUM(sai.quantity) as total_avarie');
            }

            $avarieData = $query->get();

            // Total approvisionné
            $totalSupplied = (clone $baseSupplyQuery)
                ->when($warehouse, fn($q) => $q->where('supplies.warehouse_uuid', $warehouse))
                ->sum('supply_items.quantity_supplied');

            $data = $avarieData->map(function($item) use ($totalSupplied, $warehouse) {
                return [
                    'warehouse' => $warehouse ? $item->warehouse_name : 'Global',
                    'value' => $totalSupplied > 0
                        ? round(($item->total_avarie / $totalSupplied) * 100, 2)
                        : 0
                ];
            });

            // Si vide et dates fournies
            if ($data->isEmpty() && $start && $end) {
                $data = collect([['warehouse' => $warehouse ?? 'Global', 'value' => 0]]);
            }

            return response()->json([
                'period' => ($start && $end) ? 'dynamic' : 'global',
                'data'   => $data
            ]);
        }

    }


    /**
     * Display a listing of the resource.
     * @permission StatisticsController::topConsumedProducts
     * @permission_desc Statistiques sur les articles les plus consommés en approvisionnements
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
            ->join('warehouses as w', 'w.uuid', '=', 'po.warehouse_from') // utiliser l'entrepôt de la commande
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
            $query->where('po.warehouse_from', $warehouse_uuid);
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





    private function resolveScale(Carbon $startDate, Carbon $endDate): string
    {
        $days = $startDate->diffInDays($endDate);

        return match (true) {
            $days <= 7   => 'day',
            $days <= 60  => 'week',
            default      => 'month', // plus de 'year', on garde day/week/month
        };
    }

    public function statisticsByProduct(Request $request, string $productUuid)
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : now()->subMonth()->startOfDay();
        $endDate   = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : now()->endOfDay();
        $warehouseUuid = $request->warehouse_uuid ?? null;
        $action = $request->action ?? 'price_variation'; // default action

        $productName = DB::table('produits')->where('uuid', $productUuid)->value('name') ?? 'Inconnu';

        if ($action === 'avaries_rate') {
            // Total fourni
            $totalQuantity = DB::table('supply_items as si')
                ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                ->join('purchase_orders as po', 'po.uuid', '=', 's.purchase_order_uuid')
                ->where('si.product_uuid', $productUuid)
                ->whereBetween('s.supply_date', [$startDate, $endDate])
                ->when($warehouseUuid, fn($q) => $q->where('s.warehouse_uuid', $warehouseUuid)->where('po.type', 'internal'))
                ->when(!$warehouseUuid, fn($q) => $q->where('po.type', 'external'))
                ->sum('si.quantity_supplied');

            // Total avaries
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

        // Pour price_variation et quantity_variation (graphique linéaire)
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

        switch ($action) {
            case 'price_variation':
                $itemsQuery->select('s.supply_date', DB::raw("IF(po.type='internal', si.sell_price, si.unit_price) as value"));
                break;
            case 'quantity_variation':
                $itemsQuery->select('s.supply_date', 'si.quantity_supplied as value');
                break;
            default:
                return response()->json(['error' => 'Action non supportée'], 400);
        }

        $items = $itemsQuery->get();

        $resultPoints = $items->groupBy(function ($item) {
            return Carbon::parse($item->supply_date)->format('Y-m-d');
        })->map(function ($dayItems, $day) {
            $total = collect($dayItems)->sum('value');
            return [
                'period' => $day,
                'value'  => number_format($total, 3, '.', '')
            ];
        })->values(); // reset index

        return response()->json([
            'product' => $productName,
            'scale'   => 'day',
            'points'  => $resultPoints,
        ]);
    }




















    //
}

<?php

namespace App\Http\Controllers;

use App\Enums\StockAdjustmentAction;
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















    //
}

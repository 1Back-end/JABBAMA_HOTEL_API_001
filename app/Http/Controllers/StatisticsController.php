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
     * Display a listing of the resource.
     * @permission StatisticsController::get_statistics_by_product
     * @permission_desc Statistiques sur la variation des quantités, prix unitaires et avaries des articles
     */
    public function get_statistics_by_product(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Récupération sécurisée des dates
        $start_date_input = $request->input('start_date');
        $end_date_input   = $request->input('end_date');

        $start_date = ($start_date_input && $start_date_input !== 'undefined')
            ? \Illuminate\Support\Carbon::parse($start_date_input)->startOfDay()
            : null;

        $end_date = ($end_date_input && $end_date_input !== 'undefined')
            ? Carbon::parse($end_date_input)->endOfDay()
            : null;

        // Action : variation_quantity, variation_price ou avarie
        $action = $request->input('action', 'variation_quantity');

        // Base query pour les approvisionnements
        $query = \DB::table('supply_items')
            ->join('supplies', 'supply_items.supply_uuid', '=', 'supplies.uuid')
            ->select('supply_items.*', 'supplies.supply_date')
            ->where('supply_items.product_uuid', $uuid);

        if ($start_date && $end_date) {
            $query->whereBetween('supplies.supply_date', [$start_date, $end_date]);
        }

        $supplies = $query->orderBy('supplies.supply_date', 'asc')->get();

        if ($action === 'avarie') {
            // Récupération des ajustements d'avaries
            $avaries = \DB::table('stock_adjustments_items as sai')
                ->join('stock_adjustments as sa', 'sai.stock_adjustment_uuid', '=', 'sa.uuid')
                ->where('sai.product_uuid', $uuid)
                ->where('sa.action', StockAdjustmentAction::AVARIE->value)
                ->when($start_date && $end_date, function($q) use ($start_date, $end_date) {
                    $q->whereBetween('sa.created_at', [$start_date, $end_date]);
                })
                ->selectRaw('SUM(sai.quantity) as total_avarie')
                ->first();

            // Somme totale approvisionnée
            $total_supplied = $supplies->sum('quantity_supplied');

            $taux_avarie = $total_supplied > 0
                ? ($avaries->total_avarie / $total_supplied) * 100
                : 0;

            return response()->json([
                ['date' => now()->format('Y-m-d'), 'value' => round($taux_avarie, 2)]
            ]);
        }

        // Sinon on retourne quantity ou price
        $data = $supplies->map(function($item) use ($action) {
            return [
                'date' => Carbon::parse($item->supply_date)->format('Y-m-d'),
                'value' => $action === 'variation_price'
                    ? (float) $item->unit_price
                    : (int) $item->quantity_supplied,
            ];
        });

        return response()->json($data);
    }



    /**
     * Display a listing of the resource.
     * @permission StatisticsController::topConsumedProducts
     * @permission_desc Statistiques sur les articles les plus consommés en approvisionnements
     */
    public function topConsumedProducts(Request $request)
    {
        // On peut filtrer par date si nécessaire
        $start_date = $request->input('start_date') ? $request->input('start_date') : null;
        $end_date = $request->input('end_date') ? $request->input('end_date') : null;

        $query = DB::table('supply_items as si')
            ->join('supplies as s', 'si.supply_uuid', '=', 's.uuid')
            ->join('produits as p', 'si.product_uuid', '=', 'p.uuid')
            ->select('p.uuid', 'p.name', 'p.code', DB::raw('COUNT(s.uuid) as frequency'))
            ->groupBy('p.uuid', 'p.name', 'p.code')
            ->orderByDesc('frequency');

        if ($start_date && $end_date) {
            $query->whereBetween('s.supply_date', [$start_date, $end_date]);
        }

        $data = $query->get();

        return response()->json($data);
    }













    //
}

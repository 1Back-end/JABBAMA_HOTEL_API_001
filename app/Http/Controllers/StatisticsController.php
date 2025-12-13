<?php

namespace App\Http\Controllers;

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
     * @permission StatisticsController::priceVariationAll
     * @permission_desc Statistiques sur la variation des prix des approvisionnements
     */
    public function priceVariationAll(Request $request)
    {
        // Validation des dates
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $query = Supply::with('items')->orderBy('supply_date');

        if ($request->start_date) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $query->where('supply_date', '>=', $start);
        }

        if ($request->end_date) {
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->where('supply_date', '<=', $end);
        }

        $supplies = $query->get();

        $chartData = $supplies->map(function ($supply) {
            $totalPrice = $supply->items->sum(fn($item) => (float)$item->unit_price);

            return [
                'reference' => $supply->reference,
                'date' => $supply->supply_date->format('d-m-Y'),
                'total_unit_price' => $totalPrice,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $chartData,
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StatisticsController::quantityVariation
     * @permission_desc Statistiques sur la variation des quantités des articles approvisionnées
     */
    public function quantityVariation(Request $request)
    {
        $request->validate([
            'period' => 'required|in:week,month,year',
            'week' => 'nullable|integer|min:1|max:53',
            'year' => 'nullable|integer|min:2000',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        try {
            $period = $request->period;

            // 🔹 Calcul start et end selon la période
            $start = null;
            $end = null;

            if ($period === 'week') {
                if (!$request->week || !$request->year) {
                    return response()->json(['status' => 'error', 'message' => 'Semaine et année obligatoires'], 400);
                }
                $start = Carbon::now()->setISODate($request->year, $request->week)->startOfWeek();
                $end   = Carbon::now()->setISODate($request->year, $request->week)->endOfWeek();
            } elseif ($period === 'month') {
                if (!$request->start_date || !$request->end_date) {
                    return response()->json(['status' => 'error', 'message' => 'Dates début et fin obligatoires'], 400);
                }
                $start = Carbon::parse($request->start_date)->startOfMonth();
                $end   = Carbon::parse($request->end_date)->endOfMonth();
            } elseif ($period === 'year') {
                if (!$request->year) {
                    return response()->json(['status' => 'error', 'message' => 'Année obligatoire'], 400);
                }
                // Pour l'année, on prend du 01/01 à 31/12 de l'année choisie
                $start = Carbon::createFromDate($request->year, 1, 1)->startOfDay();
                $end   = Carbon::createFromDate($request->year, 12, 31)->endOfDay();
            }

            // 🔹 Sélection de la période pour le groupement
            switch ($period) {
                case 'week':
                    $periodSelect = DB::raw("YEARWEEK(supplies.created_at, 1) as period");
                    break;
                case 'month':
                    $periodSelect = DB::raw("DATE_FORMAT(supplies.created_at, '%Y-%m') as period");
                    break;
                case 'year':
                    $periodSelect = DB::raw("DATE_FORMAT(supplies.created_at, '%Y-%m') as period"); // grouper par mois pour l'année
                    break;
            }

            $query = DB::table('supplies')
                ->join('supply_items', 'supply_items.supply_uuid', '=', 'supplies.uuid')
                ->select(
                    'supplies.reference',
                    $periodSelect,
                    DB::raw('SUM(supply_items.quantity_supplied) as total_quantity')
                )
                ->whereBetween('supplies.created_at', [$start, $end])
                ->groupBy('supplies.reference', DB::raw('period'))
                ->orderBy('period', 'ASC');

            $data = $query->get();

            // 🔹 Transformer les labels
            $data = $data->map(function($item) use ($period) {
                if ($period === 'week') {
                    $year = intdiv($item->period, 100);
                    $week = $item->period % 100;
                    $item->period = "Semaine $week / $year";
                } elseif ($period === 'month' || $period === 'year') {
                    $item->period = Carbon::createFromFormat('Y-m', $item->period)->format('m/Y');
                }
                return $item;
            });

            return response()->json([
                'status' => 'success',
                'period' => $period,
                'year'   => $request->year ?? null,
                'data'   => $data
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission StatisticsController::mostConsumedArticles
     * @permission_desc Statistiques sur les articles les plus consommés dans les approvisionnements
     */
    public function mostConsumedArticles(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'per_page'   => 'nullable|integer|min:1|max:100',
            'page'       => 'nullable|integer|min:1'
        ]);

        $start = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : null;

        $end = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : null;

        // ✅ pagination
        $perPage = $request->input('per_page', 25);

        try {
            $query = DB::table('supply_items')
                ->join('supplies', 'supplies.uuid', '=', 'supply_items.supply_uuid')
                ->join('produits', 'produits.uuid', '=', 'supply_items.product_uuid')
                ->select(
                    'produits.uuid as product_uuid',
                    'produits.code as reference',
                    'produits.name as product_name',
                    DB::raw('COUNT(supplies.uuid) as supply_count'),
                    DB::raw('SUM(supply_items.quantity_supplied) as total_quantity')
                )
                ->groupBy(
                    'produits.uuid',
                    'produits.code',
                    'produits.name'
                )
                ->orderByDesc('supply_count');

            if ($start) {
                $query->where('supplies.created_at', '>=', $start);
            }

            if ($end) {
                $query->where('supplies.created_at', '<=', $end);
            }

            $data = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data'   => $data
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }











    //
}

<?php

namespace App\Http\Controllers;

use App\Enums\ChooseClients;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\OrderMenuRestaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DataController extends Controller
{
    public function get_GLE_for_main_courante(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)->get();
            $totalGle = (int) $orders->sum(function ($order) {
                return $order->total_order;
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'nombre_factures' => $orders->count(),
                'total_gle' => $totalGle
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul du total général.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_encaissement_for_main_courante(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->whereIn('regulation_status', [
                    PaymentOrderMenusStatus::PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ])
                ->get();
            $totalEncaissement = (int) $orders->sum(function ($order) {
                return $order->computed_paid_amount;
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'nombre_encaissements' => $orders->count(),
                'total_encaissement' => $totalEncaissement
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul de l'encaissement.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_not_paid_for_main_courante(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->whereIn('regulation_status', [
                    PaymentOrderMenusStatus::NOT_PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ])
                ->get();
            $totalNotPaid = (int) $orders->sum(function ($order) {
                return $order->total_order;
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'nombre_not_paid' => $orders->count(),
                'total_not_paid' => $totalNotPaid
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul des factures non payées.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_sales_category_totals_for_main_courante(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->with('salesCategory:uuid,name,code')
                ->get();

            $categoriesTotals = $orders->groupBy(function ($order) {
                return $order->salesCategory ? $order->salesCategory->name : 'AUTRES';
            })->map(function ($group) {
                return (float) $group->sum(function ($order) {
                    return $order->total_order;
                });
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'totals_by_category' => $categoriesTotals
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul des montants par catégorie de vente.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_restaurant_bar_total(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $totalBar = (float) OrderMenuRestaurant::whereDate('created_at', $date)
                ->get()
                ->sum(function ($order) {
                    return $order->total_drinks;
                });

            return response()->json([
                'success' => true,
                'date' => $date,
                'total_bar' => $totalBar
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul du total bar.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_restaurant_total_by_client_type(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('type_clients_for_payment', TypeClientsForPaiment::DEBTOR->value)
                ->get();

            $total = (float) $orders->sum(function ($order) {
                return $order->total_order;
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'total_order' => $total
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul du total par type de client.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_count_sales_category_totals_for_main_courante(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->with('salesCategory:uuid,name,code')
                ->get();

            $categoriesCounts = $orders->groupBy(function ($order) {
                return $order->salesCategory ? $order->salesCategory->name : 'AUTRES';
            })->map(function ($group) {
                return (int) $group->count();
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'counts_by_category' => $categoriesCounts
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du comptage par catégorie de vente.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_restaurant_bar_count(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $countBar = (int) OrderMenuRestaurant::whereDate('created_at', $date)
                ->get()
                ->filter(function ($order) {
                    return $order->total_drinks > 0;
                })
                ->count();

            return response()->json([
                'success' => true,
                'date' => $date,
                'count_bar' => $countBar
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du comptage des commandes bar.",
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function get_restaurant_count_by_client_type(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $count = (int) OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('type_clients_for_payment', TypeClientsForPaiment::DEBTOR->value)
                ->count();

            return response()->json([
                'success' => true,
                'date' => $date,
                'count_order' => $count
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du comptage par type de client.",
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

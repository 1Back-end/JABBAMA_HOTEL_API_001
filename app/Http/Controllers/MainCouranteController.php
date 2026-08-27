<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Models\OrderMenuRestaurant;
use App\Models\PaymentRegulation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MainCouranteController extends Controller
{
    public function index(Request $request)
    {
        $auth = auth()->user();

        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $dateDebutGlobal = Carbon::parse($request->input('date_debut'))->toDateString();
            $dateFinGlobal = Carbon::parse($request->input('date_fin'))->toDateString();
        } else {
            $dateInput = $request->input('date', now()->toDateString());

            if (str_contains($dateInput, ' to ')) {
                $dates = explode(' to ', $dateInput);
                $dateDebutGlobal = Carbon::parse(trim($dates[0]))->toDateString();
                $dateFinGlobal = Carbon::parse(trim($dates[1] ?? $dates[0]))->toDateString();
            } elseif (str_contains($dateInput, ' - ')) {
                $dates = explode(' - ', $dateInput);
                $dateDebutGlobal = Carbon::parse(trim($dates[0]))->toDateString();
                $dateFinGlobal = Carbon::parse(trim($dates[1] ?? $dates[0]))->toDateString();
            } else {
                $dateDebutGlobal = Carbon::parse($dateInput)->toDateString();
                $dateFinGlobal = $dateDebutGlobal;
            }
        }

        $dateDebutP1 = $dateDebutGlobal;
        $dateFinP1   = $dateFinGlobal;

        $dateDebutP2 = $request->filled('date_debut_p2') ? Carbon::parse($request->date_debut_p2)->toDateString() : $dateDebutGlobal;
        $dateFinP2   = $request->filled('date_fin_p2') ? Carbon::parse($request->date_fin_p2)->toDateString() : $dateFinGlobal;

        try {
            $calculateMetrics = function ($startDate, $endDate) {
                $query = OrderMenuRestaurant::with([
                    'salesCategory:uuid,name,code',
                    'items.menu:uuid,is_generated_from_complement',
                    'drinks'
                ])
                    ->where('status', MenuOrderStatus::FACTURATE->value);

                if ($startDate === $endDate) {
                    $query->whereDate('created_at', $startDate);
                } else {
                    $query->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }

                $orders = $query->get();

                $groupedOrders = $orders->groupBy(function ($order) {
                    return $order->salesCategory ? $order->salesCategory->name : 'AUTRES';
                });

                $categoriesTotals = $groupedOrders->map(function ($group) {
                    return (float) $group->sum(function ($order) {
                        return $order->items->filter(function ($item) {
                            return $item->menu && !$item->menu->is_generated_from_complement;
                        })->sum('total_price');
                    });
                });

                $categoriesCounts = $groupedOrders->map(function ($group) {
                    return (int) $group->sum(function ($order) {
                        return $order->items->filter(function ($item) {
                            return $item->menu && !$item->menu->is_generated_from_complement;
                        })->sum('quantity_exactly');
                    });
                });

                $totalBar = (float) $orders->sum('total_drinks');
                $totalDrinksQuantity = (int) $orders->sum(function ($order) {
                    return $order->drinks ? $order->drinks->sum('quantity_exactly') : 0;
                });

                $totalAmountRoomService = (float) $orders->where('is_room_service', true)->sum('price_for_room_service');
                $totalQuantityRoomService = (int) $orders->where('is_room_service', true)->sum('quantity_for_room_service');

                $totalAmountDivers = 0;
                $totalQuantityDivers = 0;

                foreach ($orders as $order) {
                    $uniqueItems = $order->items->unique('uuid');
                    $validItems = $uniqueItems->filter(function ($item) {
                        return $item->menu && (bool) $item->menu->is_generated_from_complement === true;
                    });
                    $totalQuantityDivers += (int) $validItems->sum('quantity_exactly');
                    $totalAmountDivers += (float) $validItems->sum(function ($item) {
                        return $item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));
                    });
                }

                // Calcul corrigé de l'encaissement basé sur votre logique
                $encaissementQuery = OrderMenuRestaurant::whereIn('regulation_status', [
                    PaymentOrderMenusStatus::PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ]);

                if ($startDate === $endDate) {
                    $encaissementQuery->whereDate('created_at', $startDate);
                } else {
                    $encaissementQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }

                $totalEncaissement = (float) $encaissementQuery->get()->sum(function ($order) {
                    return $order->computed_paid_amount ?? 0;
                });

                $recouvrementsQuery = PaymentRegulation::where('type', 'recouvrement');

                if ($startDate === $endDate) {
                    $recouvrementsQuery->whereDate('created_at', $startDate);
                } else {
                    $recouvrementsQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }
                $totalRecouvrements = (float) $recouvrementsQuery->sum('amount');

                return [
                    'totals_by_category' => $categoriesTotals,
                    'count_by_category' => $categoriesCounts,
                    'total_bar' => $totalBar,
                    'total_drinks_quantity' => $totalDrinksQuantity,
                    'total_amount_room_service' => $totalAmountRoomService,
                    'total_quantity_room_service' => $totalQuantityRoomService,
                    'total_amount_divers' => $totalAmountDivers,
                    'total_quantity_divers' => $totalQuantityDivers,
                    'total_encaissement' => $totalEncaissement,
                    'total_recouvrements' => $totalRecouvrements,
                ];
            };

            $dataP1 = $calculateMetrics($dateDebutP1, $dateFinP1);
            $dataP2 = $calculateMetrics($dateDebutP2, $dateFinP2);

            return response()->json([
                'success' => true,
                'periode_1' => ['date_debut' => $dateDebutP1, 'date_fin' => $dateFinP1],
                'periode_2' => ['date_debut' => $dateDebutP2, 'date_fin' => $dateFinP2],

                'totals_by_category' => $dataP1['totals_by_category'],
                'count_by_category' => $dataP1['count_by_category'],
                'total_bar' => $dataP1['total_bar'],
                'total_drinks_quantity' => $dataP1['total_drinks_quantity'],
                'total_amount_room_service' => $dataP1['total_amount_room_service'],
                'total_quantity_room_service' => $dataP1['total_quantity_room_service'],
                'total_amount_divers' => $dataP1['total_amount_divers'],
                'total_quantity_divers' => $dataP1['total_quantity_divers'],
                'total_encaissement_p1' => $dataP1['total_encaissement'],
                'total_recouvrements_p1' => $dataP1['total_recouvrements'],

                'p2_totals_by_category' => $dataP2['totals_by_category'],
                'p2_count_by_category' => $dataP2['count_by_category'],
                'p2_total_bar' => $dataP2['total_bar'],
                'p2_total_drinks_quantity' => $dataP2['total_drinks_quantity'],
                'p2_total_amount_room_service' => $dataP2['total_amount_room_service'],
                'p2_total_quantity_room_service' => $dataP2['total_quantity_room_service'],
                'p2_total_amount_divers' => $dataP2['total_amount_divers'],
                'p2_total_quantity_divers' => $dataP2['total_quantity_divers'],
                'total_encaissement_p2' => $dataP2['total_encaissement'],
                'total_recouvrements_p2' => $dataP2['total_recouvrements'],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données : ' . $e->getMessage()
            ], 500);
        }
    }
}

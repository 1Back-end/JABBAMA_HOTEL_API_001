<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantDefectiveItem;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OthersStatisticsController extends Controller
{
    public function menusIndex(Request $request)
    {
        $startDate = $request->start_date
            ? Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay()
            : now()->startOfDay();

        $endDate = $request->end_date
            ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
            : now()->endOfDay();

        $ordersHosted = OrderMenuRestaurant::where('is_used_restaurant_rooms', true)
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::DEBTOR->value,
                TypeClientsForPaiment::PARTNER->value
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('items')
            ->with(['items'])
            ->get();

        $sumHosted = $ordersHosted->sum(fn($o) => $o->total_items);
        $countHosted = $ordersHosted->count();
        $hostedAvg = $countHosted > 0 ? round($sumHosted / $countHosted, 2) : 0;

        $ordersNonHostedDiverse = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::DEBTOR->value,
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('items')
            ->with(['items'])
            ->get();

        $sumNonHosted = $ordersNonHostedDiverse->sum(fn($o) => $o->total_items);
        $countNonHosted = $ordersNonHostedDiverse->count();
        $nonHostedAvg = $countNonHosted > 0 ? round($sumNonHosted / $countNonHosted, 2) : 0;

        $ordersPartner = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('items')
            ->with(['items'])
            ->get();

        $sumPartner = $ordersPartner->sum(fn($o) => $o->total_items);
        $countPartner = $ordersPartner->count();
        $partnerAvg = $countPartner > 0 ? round($sumPartner / $countPartner, 2) : 0;

        // Somme des moyennes des rubriques
        $totalSumCross = (float) ($hostedAvg + $nonHostedAvg + $partnerAvg);
        $totalOrdersCross = (int) ($countHosted + $countNonHosted + $countPartner);

        $statistics = [
            [
                'cross_referenced_average_ticket' => [
                    'total_sum_prices' => $totalSumCross,
                    'total_orders' => (int) $totalOrdersCross,
                    'value' => tap($totalOrdersCross > 0 ? round($totalSumCross / $totalOrdersCross, 2) : 0, function($val) use ($totalSumCross, $totalOrdersCross) {
                        \Log::info('Cross Referenced Average Ticket calculated', [
                            'total_sum' => $totalSumCross,
                            'total_orders' => $totalOrdersCross,
                            'result' => $val
                        ]);
                    }),
                ],
                'hosted_sales_average_price' => [
                    'total_sum_prices' => (float) $sumHosted,
                    'total_orders' => (int) $countHosted,
                    'value' => $hostedAvg,
                ],
                'non_hosted_diverse_average_price' => [
                    'total_sum_prices' => (float) $sumNonHosted,
                    'total_orders' => (int) $countNonHosted,
                    'value' => $nonHostedAvg,
                ],
                'partner_average_ticket' => [
                    'total_sum_prices' => (float) $sumPartner,
                    'total_orders' => (int) $countPartner,
                    'value' => $partnerAvg,
                ],
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    public function drinksIndex(Request $request)
    {
        $startDate = $request->start_date
            ? Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay()
            : now()->startOfDay();

        $endDate = $request->end_date
            ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
            : now()->endOfDay();

        $ordersHosted = OrderMenuRestaurant::where('is_used_restaurant_rooms', true)
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::DEBTOR->value,
                TypeClientsForPaiment::PARTNER->value
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('drinks')
            ->with(['drinks'])
            ->get();

        $sumHosted = $ordersHosted->sum(fn($o) => $o->total_drinks ?? $o->total_items);
        $countHosted = $ordersHosted->count();
        $hostedAvg = $countHosted > 0 ? round($sumHosted / $countHosted, 2) : 0;

        $ordersNonHostedDiverse = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::DEBTOR->value,
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('drinks')
            ->with(['drinks'])
            ->get();

        $sumNonHosted = $ordersNonHostedDiverse->sum(fn($o) => $o->total_drinks ?? $o->total_items);
        $countNonHosted = $ordersNonHostedDiverse->count();
        $nonHostedAvg = $countNonHosted > 0 ? round($sumNonHosted / $countNonHosted, 2) : 0;

        $ordersPartner = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('drinks')
            ->with(['drinks'])
            ->get();

        $sumPartner = $ordersPartner->sum(fn($o) => $o->total_drinks ?? $o->total_items);
        $countPartner = $ordersPartner->count();
        $partnerAvg = $countPartner > 0 ? round($sumPartner / $countPartner, 2) : 0;

        $totalSumCross = (float) ($hostedAvg + $nonHostedAvg + $partnerAvg);
        $totalOrdersCross = (int) ($countHosted + $countNonHosted + $countPartner);

        $statistics = [
            [
                'cross_referenced_average_ticket' => [
                    'total_sum_prices' => $totalSumCross,
                    'total_orders' => (int) $totalOrdersCross,
                    'value' => tap($totalOrdersCross > 0 ? round($totalSumCross / $totalOrdersCross, 2) : 0, function($val) use ($totalSumCross, $totalOrdersCross) {
                    }),
                ],
                'hosted_sales_average_price' => [
                    'total_sum_prices' => (float) $sumHosted,
                    'total_orders' => (int) $countHosted,
                    'value' => $hostedAvg,
                ],
                'non_hosted_diverse_average_price' => [
                    'total_sum_prices' => (float) $sumNonHosted,
                    'total_orders' => (int) $countNonHosted,
                    'value' => $nonHostedAvg,
                ],
                'partner_average_ticket' => [
                    'total_sum_prices' => (float) $sumPartner,
                    'total_orders' => (int) $countPartner,
                    'value' => $partnerAvg,
                ],
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    public function topSellingItemsIndex(Request $request)
    {
        $startDate = $request->start_date
            ? Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay()
            : now()->startOfDay();

        $endDate = $request->end_date
            ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
            : now()->endOfDay();

        $allTopMenus = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('menus_restaurant_uuid')
            ->whereHas('menu', function ($query) {
                $query->where('is_generated_from_complement', false);
            })
            ->with(['menu'])
            ->get()
            ->groupBy('menus_restaurant_uuid')
            ->map(function ($items) {
                $firstItem = $items->first();
                $menu = $firstItem->menu;

                $totalQuantity = (int) $items->sum('quantity_exactly');
                $totalRevenue = (float) $items->sum(fn($item) => ($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));

                return [
                    'uuid' => $firstItem->menus_restaurant_uuid,
                    'name' => $menu ? $menu->name : 'Plat inconnu',
                    'code' => $menu ? $menu->code : null,
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ];
            })
            ->filter(fn($item) => $item['total_quantity'] > 0 && $item['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();


        $allTopDrinks = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('drink_restaurant_uuid')
            ->with(['drinkConfig.product'])
            ->get()
            ->groupBy('drink_restaurant_uuid')
            ->map(function ($drinks) {
                $firstDrink = $drinks->first();
                $config = $firstDrink->drinkConfig;

                $totalQuantity = (int) $drinks->sum('quantity_exactly');
                $totalRevenue = (float) $drinks->sum(fn($drink) => ($drink->unit_price ?? 0) * ($drink->quantity_exactly ?? 0));

                return [
                    'uuid' => $firstDrink->drink_restaurant_uuid,
                    'name' => $config?->product?->name ?? 'Boisson inconnue',
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ];
            })
            ->filter(fn($drink) => $drink['total_quantity'] > 0 && $drink['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        $allTopComplements = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('menus_restaurant_uuid')
            ->whereHas('menu', function ($query) {
                $query->where('is_generated_from_complement', true);
            })
            ->with(['menu'])
            ->get()
            ->groupBy('menus_restaurant_uuid')
            ->map(function ($items) {
                $firstItem = $items->first();
                $menu = $firstItem->menu;

                $totalQuantity = (int) $items->sum('quantity_exactly');
                $totalRevenue = (float) $items->sum(fn($item) => ($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));

                return [
                    'uuid' => $firstItem->menus_restaurant_uuid,
                    'name' => $menu ? $menu->name : 'Complément inconnu',
                    'code' => $menu ? $menu->code : null,
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ];
            })
            ->filter(fn($item) => $item['total_quantity'] > 0 && $item['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'top_menus' => $allTopMenus,
                'top_drinks' => $allTopDrinks,
                'top_complements' => $allTopComplements,
            ]
        ]);
    }

    public function get_defective_statistics(Request $request)
    {
        $startDate = $request->start_date
            ? Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay()
            : now()->startOfDay();

        $endDate = $request->end_date
            ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
            : now()->endOfDay();

        $defectiveItems = OrderMenuRestaurantDefectiveItem::with(['item.menu'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->filter(function ($defective) {
                return $defective->item !== null && $defective->item->menu !== null;
            });

        $defectiveMenus = $defectiveItems->groupBy(function ($defective) {
            return $defective->item->menus_restaurant_uuid;
        })->map(function ($group) {
            $first = $group->first();
            $menu = $first->item->menu;

            $totalQuantity = $group->sum('quantity');
            $totalRevenue = $group->sum(function ($defective) {
                return $defective->quantity * $defective->item->unit_price;
            });

            return [
                'menu_uuid' => $first->item->menus_restaurant_uuid,
                'name' => $menu->name,
                'total_quantity' => $totalQuantity,
                'total_revenue' => $totalRevenue,
            ];
        })->values()->sortByDesc('total_quantity')->values();

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des pertes (menus défectueux) récupérées avec succès.',
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'top_defective_menus' => $defectiveMenus
            ]
        ]);
    }
}

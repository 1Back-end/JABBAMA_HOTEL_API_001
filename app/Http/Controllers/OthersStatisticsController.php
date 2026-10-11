<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\ConfigurationsComplement;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantDefectiveItem;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderMenuRestaurantItemComplement;
use App\Models\OrderRestaurantDrink;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // 1. Clients hébergés (Débiteurs + Partenaires)
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

        // 2. Clients non hébergés (Débiteurs + Partenaires)
        $ordersNonHostedAll = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::DEBTOR->value,
                TypeClientsForPaiment::PARTNER->value
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('items')
            ->with(['items'])
            ->get();

        $sumNonHostedAll = $ordersNonHostedAll->sum(fn($o) => $o->total_items);
        $countNonHostedAll = $ordersNonHostedAll->count();

        // Somme totale combinée (Hébergés + Non hébergés globaux)
        $sumTotalOrders = $sumHosted + $sumNonHostedAll;
        $countTotalOrders = $countHosted + $countNonHostedAll;

        // 3. Clients non hébergés divers (Débiteurs uniquement)
        $ordersNonHostedDiverse = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::DEBTOR->value,
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('items')
            ->with(['items'])
            ->get();

        $sumNonHostedDiverse = $ordersNonHostedDiverse->sum(fn($o) => $o->total_items);
        $countNonHostedDiverse = $ordersNonHostedDiverse->count();

        // 4. Commandes Partenaires (Tous confondus)
        $ordersPartner = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('items')
            ->with(['items'])
            ->get();

        $sumPartner = $ordersPartner->sum(fn($o) => $o->total_items);
        $countPartner = $ordersPartner->count();

        $statistics = [
            [
                'cross_referenced_average_ticket' => [
                    'total_sum_prices' => (float) $sumTotalOrders,
                    'total_orders' => (int) $countTotalOrders,
                    'value' => $countTotalOrders > 0 ? round($sumTotalOrders / $countTotalOrders, 2) : 0,
                ],
                'hosted_sales_average_price' => [
                    'total_sum_prices' => (float) $sumHosted,
                    'total_orders' => (int) $countHosted,
                    'value' => $countHosted > 0 ? round($sumHosted / $countHosted, 2) : 0,
                ],
                'non_hosted_diverse_average_price' => [
                    'total_sum_prices' => (float) $sumNonHostedDiverse,
                    'total_orders' => (int) $countNonHostedDiverse,
                    'value' => $countNonHostedDiverse > 0 ? round($sumNonHostedDiverse / $countNonHostedDiverse, 2) : 0,
                ],
                'partner_average_ticket' => [
                    'total_sum_prices' => (float) $sumPartner,
                    'total_orders' => (int) $countPartner,
                    'value' => $countPartner > 0 ? round($sumPartner / $countPartner, 2) : 0,
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

        // 1. Clients hébergés (Débiteurs + Partenaires)
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

        // 2. Clients non hébergés divers (Débiteurs uniquement)
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

        // Somme totale combinée (Hébergés + Non hébergés divers) pour le ticket croisé
        $sumTotalOrders = $sumHosted + $sumNonHosted;
        $countTotalOrders = $countHosted + $countNonHosted;

        // 3. Commandes Partenaires (Tous confondus)
        $ordersPartner = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->has('drinks')
            ->with(['drinks'])
            ->get();

        $sumPartner = $ordersPartner->sum(fn($o) => $o->total_drinks ?? $o->total_items);
        $countPartner = $ordersPartner->count();

        $statistics = [
            [
                'cross_referenced_average_ticket' => [
                    'total_sum_prices' => (float) $sumTotalOrders,
                    'total_orders' => (int) $countTotalOrders,
                    'value' => $countTotalOrders > 0 ? round($sumTotalOrders / $countTotalOrders, 2) : 0,
                ],
                'hosted_sales_average_price' => [
                    'total_sum_prices' => (float) $sumHosted,
                    'total_orders' => (int) $countHosted,
                    'value' => $countHosted > 0 ? round($sumHosted / $countHosted, 2) : 0,
                ],
                'non_hosted_diverse_average_price' => [
                    'total_sum_prices' => (float) $sumNonHosted,
                    'total_orders' => (int) $countNonHosted,
                    'value' => $countNonHosted > 0 ? round($sumNonHosted / $countNonHosted, 2) : 0,
                ],
                'partner_average_ticket' => [
                    'total_sum_prices' => (float) $sumPartner,
                    'total_orders' => (int) $countPartner,
                    'value' => $countPartner > 0 ? round($sumPartner / $countPartner, 2) : 0,
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

        // 1. Menus classiques
        $allTopMenus = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('menus_restaurant_uuid')
            ->whereHas('menu', function ($query) {
                $query->where('is_generated_from_complement', false);
            })
            ->whereHas('order', function ($query) {
                $query->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ]);
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
                    'name' => $menu ? $menu->name : '',
                    'code' => $menu ? $menu->code : null,
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ];
            })
            ->filter(fn($item) => $item['total_quantity'] > 0 && $item['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        // 2. Boissons classiques
        $allTopDrinks = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('drink_restaurant_uuid')
            ->whereHas('order', function ($query) {
                $query->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ]);
            })
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
                    'name' => $config?->product?->name ?? '',
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ];
            })
            ->filter(fn($drink) => $drink['total_quantity'] > 0 && $drink['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        // 3. Compléments et Boissons issus des compléments (via OrderMenuRestaurantItem)
        $complementItems = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('menus_restaurant_uuid')
            ->whereHas('menu', function ($query) {
                $query->where('is_generated_from_complement', true);
            })
            ->whereHas('order', function ($query) {
                $query->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ]);
            })
            ->with(['menu'])
            ->get()
            ->groupBy('menus_restaurant_uuid');

        $complementsList = collect();
        $drinksFromComplementsList = collect();

        foreach ($complementItems as $uuid => $items) {
            $firstItem = $items->first();
            $menu = $firstItem->menu;

            $complementConfig = ConfigurationsComplement::where('uuid', $uuid)->first();
            $complementType = $complementConfig ? strtolower(trim($complementConfig->menus_complement_type)) : null;

            $totalQuantity = (int) $items->sum('quantity_exactly');
            $totalRevenue = (float) $items->sum(fn($item) => ($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));

            if ($totalQuantity > 0 && $totalRevenue > 0) {
                $formattedItem = [
                    'uuid' => $uuid,
                    'name' => $menu ? $menu->name : '',
                    'code' => $menu ? $menu->code : null,
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ];

                if ($complementType === 'boisson' || $complementType === 'boisson4') {
                    $drinksFromComplementsList->push($formattedItem);
                } elseif ($complementType === 'complement') {
                    $complementsList->push($formattedItem);
                }
            }
        }

        // 4. Compléments issus de la table orders_items_complements (basé sur configuration_complement_uuid)
        $tableComplements = OrderMenuRestaurantItemComplement::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('configuration_complement_uuid')
            ->whereHas('order', function ($query) {
                $query->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ]);
            })
            ->with(['complement'])
            ->get()
            ->groupBy('configuration_complement_uuid');

        foreach ($tableComplements as $configUuid => $complements) {
            $firstComp = $complements->first();
            $complementConfig = $firstComp->complement; // Instance de ConfigurationsComplement

            $totalQuantity = (int) $complements->sum('quantity');
            $totalRevenue = 0.0; // Ajustez si un calcul de prix unitaire est nécessaire

            if ($totalQuantity > 0) {
                $complementsList->push([
                    'uuid' => $configUuid,
                    'name' => $complementConfig ? $complementConfig->name : '',
                    'code' => $complementConfig ? $complementConfig->code : null,
                    'total_quantity' => $totalQuantity,
                    'total_revenue' => $totalRevenue,
                ]);
            }
        }

        // Fusion et nettoyage final des doublons potentiels sur top_complements
        $allTopComplements = $complementsList
            ->groupBy('uuid')
            ->map(function ($group) {
                return [
                    'uuid' => $group->first()['uuid'],
                    'name' => $group->first()['name'],
                    'code' => $group->first()['code'],
                    'total_quantity' => $group->sum('total_quantity'),
                    'total_revenue' => $group->sum('total_revenue'),
                ];
            })
            ->filter(fn($item) => $item['total_quantity'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        $allTopDrinksFromComplements = $drinksFromComplementsList
            ->filter(fn($item) => $item['total_quantity'] > 0 && $item['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'top_menus' => $allTopMenus,
                'top_drinks' => $allTopDrinks,
                'top_complements' => $allTopComplements,
                'top_drinks_from_complements' => $allTopDrinksFromComplements,
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

        $defectiveItemsQuery = OrderMenuRestaurantDefectiveItem::with([
            'item' => function ($query) {
                $query->withTrashed();
            },
            'item.menu'
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($request->filled('created_by'), function ($query) use ($request) {
                $query->where('created_by', $request->input('created_by'));
            })
            ->when($request->filled('user_id'), function ($query) use ($request) {
                $query->where('created_by', $request->input('user_id'));
            });

        $defectiveItems = $defectiveItemsQuery->get()->filter(function ($defective) {
            return $defective->item !== null && $defective->item->menu !== null;
        });

        if ($defectiveItems->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Statistiques des pertes (menus défectueux) récupérées avec succès.',
                'data' => [
                    'start_date' => $startDate->format('d-m-Y'),
                    'end_date' => $endDate->format('d-m-Y'),
                    'top_defective_menus' => []
                ]
            ]);
        }

        $menuUuids = $defectiveItems->map(function ($defective) {
            return $defective->item->menus_restaurant_uuid;
        })->unique()->filter()->values();

        $orderedQuery = OrderMenuRestaurantItem::withTrashed()
            ->whereIn('menus_restaurant_uuid', $menuUuids)
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($request->filled('created_by')) {
            $orderedQuery->where('created_by', $request->input('created_by'));
        }
        if ($request->filled('user_id')) {
            $orderedQuery->where('created_by', $request->input('user_id'));
        }

        $orderStatsPerMenu = $orderedQuery
            ->select(
                'menus_restaurant_uuid',
                DB::raw('COUNT(*) as total_orders_count'),
                DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
            )
            ->groupBy('menus_restaurant_uuid')
            ->get()
            ->keyBy('menus_restaurant_uuid');

        $defectiveMenus = $defectiveItems->groupBy(function ($defective) {
            return $defective->item->menus_restaurant_uuid;
        })->map(function ($group) use ($orderStatsPerMenu) {
            $first = $group->first();
            $menuUuid = $first->item->menus_restaurant_uuid;
            $menu = $first->item->menu;

            $totalDefectiveQuantity = $group->sum('quantity');

            $totalRevenue = $group->sum(function ($def) {
                return $def->quantity * ($def->item->unit_price ?? 0);
            });

            $orderStats = $orderStatsPerMenu[$menuUuid] ?? null;
            $totalTimesOrdered = $orderStats ? (int) $orderStats->total_orders_count : 0;


            $currentOrderedQty = $orderStats ? (int) $orderStats->total_ordered_quantity : 0;
            $totalOrderedQuantity = $currentOrderedQty + $totalDefectiveQuantity;

            $defectiveRatio = $totalOrderedQuantity > 0
                ? round(($totalDefectiveQuantity / $totalOrderedQuantity) * 100, 2)
                : 0;

            return [
                'menu_uuid' => $menuUuid,
                'name' => $menu->name,
                'total_times_ordered' => $totalTimesOrdered,
                'total_ordered_quantity' => $totalOrderedQuantity,
                'total_defective_quantity' => (int) $totalDefectiveQuantity,
                'defective_ratio_percentage' => $defectiveRatio . '%',
                'total_revenue_loss' => $totalRevenue,
            ];
        })->values()->sortByDesc('total_defective_quantity')->values();

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

    public function clientConsumptionHistory(Request $request)
    {
        $startDate = $request->filled('start_date')
            ? Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay()
            : null;

        $endDate = $request->filled('end_date')
            ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
            : null;

        $query = OrderMenuRestaurant::query()
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::PARTNER->value
            ])
            ->whereHas('partners_restaurant');

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        if ($request->filled('partner_uuid')) {
            $query->where('partners_restaurant_uuid', $request->partner_uuid);
        }

        $orders = $query->with([
            'partners_restaurant',
            'items.menu',
            'items.complements.complement',
            'drinks.drinkConfig.product',
        ])->get();

        $clientHistory = $orders->groupBy(fn($order) => $order->partners_restaurant_uuid ?? 'Inconnu')
            ->map(function ($clientOrders) {
                $firstOrder = $clientOrders->first();
                $partner = $firstOrder->partners_restaurant;

                $clientName = $partner ? trim(($partner->first_name ?? '') . ' ' . ($partner->last_name ?? '')) : '';
                if (empty($clientName)) {
                    $clientName = $partner->full_name ?? '';
                }

                $menusList = collect();
                $drinksList = collect();
                $complementsList = collect();
                $drinksFromComplementsList = collect();

                foreach ($clientOrders as $order) {
                    if ($order->items) {
                        foreach ($order->items as $item) {
                            $price = (float) ($item->unit_price ?? 0);
                            $qty = (int) ($item->quantity_exactly ?? 0);

                            if ($price <= 0 || $qty <= 0) {
                                continue;
                            }

                            $menu = $item->menu;
                            $isComplement = $menu ? $menu->is_generated_from_complement : false;

                            $formattedItem = [
                                'uuid' => $item->menus_restaurant_uuid,
                                'name' => $menu ? $menu->name : 'Inconnu',
                                'code' => $menu ? $menu->code : null,
                                'total_quantity' => $qty,
                                'total_revenue' => $price * $qty,
                            ];

                            if (!$isComplement) {
                                $menusList->push($formattedItem);
                            } else {
                                $complementConfig = ConfigurationsComplement::where('uuid', $item->menus_restaurant_uuid)->first();
                                $complementType = $complementConfig ? strtolower(trim($complementConfig->menus_complement_type)) : null;

                                if ($complementType === 'boisson') {
                                    $drinksFromComplementsList->push($formattedItem);
                                } elseif ($complementType === 'complement') {
                                    $complementsList->push($formattedItem);
                                }
                            }
                        }
                    }

                    if ($order->drinks) {
                        foreach ($order->drinks as $drink) {
                            $price = (float) ($drink->unit_price ?? 0);
                            $qty = (int) ($drink->quantity_exactly ?? 0);

                            if ($price <= 0 || $qty <= 0) {
                                continue;
                            }

                            $config = $drink->drinkConfig;

                            $drinksList->push([
                                'uuid' => $drink->drink_restaurant_uuid,
                                'name' => $config?->product?->name ?? '',
                                'total_quantity' => $qty,
                                'total_revenue' => $price * $qty,
                            ]);
                        }
                    }
                }

                $aggregateAndSort = function ($collection) {
                    return $collection->groupBy('uuid')->map(function ($group) {
                        $first = $group->first();
                        return [
                            'uuid' => $first['uuid'],
                            'name' => $first['name'],
                            'code' => $first['code'] ?? null,
                            'total_quantity' => (int) $group->sum('total_quantity'),
                            'total_revenue' => (float) $group->sum('total_revenue'),
                        ];
                    })->sortByDesc('total_quantity')->values();
                };

                return [
                    'client_uuid' => $partner?->uuid,
                    'client_code' => $partner?->code,
                    'client_name' => $clientName,
                    'top_menus' => $aggregateAndSort($menusList),
                    'top_drinks' => $aggregateAndSort($drinksList),
                    'top_complements' => $aggregateAndSort($complementsList),
                    'top_drinks_from_complements' => $aggregateAndSort($drinksFromComplementsList),
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => $clientHistory
        ]);
    }
}

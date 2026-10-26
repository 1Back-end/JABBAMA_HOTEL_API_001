<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Enums\TypeMenu;
use App\Models\ConfigurationsComplement;
use App\Models\MenuRestaurant;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantDefectiveDrink;
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
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        } else {
            $firstOrderDate = OrderMenuRestaurant::min('created_at');
            $startDate = $firstOrderDate
                ? Carbon::parse($firstOrderDate)->startOfDay()
                : now()->startOfDay();
        }
        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        } else {
            $endDate = now()->subDay()->endOfDay();
        }

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

        $sumTotalOrders = $sumHosted + $sumNonHostedAll;
        $countTotalOrders = $countHosted + $countNonHostedAll;

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
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        } else {
            $firstOrderDate = OrderMenuRestaurant::min('created_at');
            $startDate = $firstOrderDate
                ? Carbon::parse($firstOrderDate)->startOfDay()
                : now()->startOfDay();
        }
        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        } else {
            $endDate = now()->subDay()->endOfDay();
        }

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

        $sumTotalOrders = $sumHosted + $sumNonHosted;
        $countTotalOrders = $countHosted + $countNonHosted;

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

    public function print_menusIndex_and_drinksIndex(Request $request)
    {
        try {
            if ($request->filled('start_date')) {
                $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            } else {
                $firstOrderDate = OrderMenuRestaurant::min('created_at');
                $startDate = $firstOrderDate
                    ? Carbon::parse($firstOrderDate)->startOfDay()
                    : now()->startOfDay();
            }

            if ($request->filled('end_date')) {
                $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
            } else {
                $endDate = now()->subDay()->endOfDay();
            }

            $ordersHostedMenu = OrderMenuRestaurant::where('is_used_restaurant_rooms', true)
                ->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('items')
                ->with(['items'])
                ->get();

            $sumHostedMenu = $ordersHostedMenu->sum(fn($o) => $o->total_items);
            $countHostedMenu = $ordersHostedMenu->count();

            $ordersNonHostedAllMenu = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
                ->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('items')
                ->with(['items'])
                ->get();

            $sumNonHostedAllMenu = $ordersNonHostedAllMenu->sum(fn($o) => $o->total_items);
            $countNonHostedAllMenu = $ordersNonHostedAllMenu->count();

            $sumTotalOrdersMenu = $sumHostedMenu + $sumNonHostedAllMenu;
            $countTotalOrdersMenu = $countHostedMenu + $countNonHostedAllMenu;

            $ordersNonHostedDiverseMenu = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
                ->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('items')
                ->with(['items'])
                ->get();

            $sumNonHostedDiverseMenu = $ordersNonHostedDiverseMenu->sum(fn($o) => $o->total_items);
            $countNonHostedDiverseMenu = $ordersNonHostedDiverseMenu->count();

            $ordersPartnerMenu = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('items')
                ->with(['items'])
                ->get();

            $sumPartnerMenu = $ordersPartnerMenu->sum(fn($o) => $o->total_items);
            $countPartnerMenu = $ordersPartnerMenu->count();

            $menuStatistics = [
                [
                    'cross_referenced_average_ticket' => [
                        'total_sum_prices' => (float) $sumTotalOrdersMenu,
                        'total_orders' => (int) $countTotalOrdersMenu,
                        'value' => $countTotalOrdersMenu > 0 ? round($sumTotalOrdersMenu / $countTotalOrdersMenu, 2) : 0,
                    ],
                    'hosted_sales_average_price' => [
                        'total_sum_prices' => (float) $sumHostedMenu,
                        'total_orders' => (int) $countHostedMenu,
                        'value' => $countHostedMenu > 0 ? round($sumHostedMenu / $countHostedMenu, 2) : 0,
                    ],
                    'non_hosted_diverse_average_price' => [
                        'total_sum_prices' => (float) $sumNonHostedDiverseMenu,
                        'total_orders' => (int) $countNonHostedDiverseMenu,
                        'value' => $countNonHostedDiverseMenu > 0 ? round($sumNonHostedDiverseMenu / $countNonHostedDiverseMenu, 2) : 0,
                    ],
                    'partner_average_ticket' => [
                        'total_sum_prices' => (float) $sumPartnerMenu,
                        'total_orders' => (int) $countPartnerMenu,
                        'value' => $countPartnerMenu > 0 ? round($sumPartnerMenu / $countPartnerMenu, 2) : 0,
                    ],
                ]
            ];

            $ordersHostedDrink = OrderMenuRestaurant::where('is_used_restaurant_rooms', true)
                ->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                    TypeClientsForPaiment::PARTNER->value
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('drinks')
                ->with(['drinks'])
                ->get();

            $sumHostedDrink = $ordersHostedDrink->sum(fn($o) => $o->total_drinks ?? $o->total_items);
            $countHostedDrink = $ordersHostedDrink->count();

            $ordersNonHostedDiverseDrink = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
                ->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::DEBTOR->value,
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('drinks')
                ->with(['drinks'])
                ->get();

            $sumNonHostedDrink = $ordersNonHostedDiverseDrink->sum(fn($o) => $o->total_drinks ?? $o->total_items);
            $countNonHostedDrink = $ordersNonHostedDiverseDrink->count();

            $sumTotalOrdersDrink = $sumHostedDrink + $sumNonHostedDrink;
            $countTotalOrdersDrink = $countHostedDrink + $countNonHostedDrink;

            $ordersPartnerDrink = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->has('drinks')
                ->with(['drinks'])
                ->get();

            $sumPartnerDrink = $ordersPartnerDrink->sum(fn($o) => $o->total_drinks ?? $o->total_items);
            $countPartnerDrink = $ordersPartnerDrink->count();

            $drinkStatistics = [
                [
                    'cross_referenced_average_ticket' => [
                        'total_sum_prices' => (float) $sumTotalOrdersDrink,
                        'total_orders' => (int) $countTotalOrdersDrink,
                        'value' => $countTotalOrdersDrink > 0 ? round($sumTotalOrdersDrink / $countTotalOrdersDrink, 2) : 0,
                    ],
                    'hosted_sales_average_price' => [
                        'total_sum_prices' => (float) $sumHostedDrink,
                        'total_orders' => (int) $countHostedDrink,
                        'value' => $countHostedDrink > 0 ? round($sumHostedDrink / $countHostedDrink, 2) : 0,
                    ],
                    'non_hosted_diverse_average_price' => [
                        'total_sum_prices' => (float) $sumNonHostedDrink,
                        'total_orders' => (int) $countNonHostedDrink,
                        'value' => $countNonHostedDrink > 0 ? round($sumNonHostedDrink / $countNonHostedDrink, 2) : 0,
                    ],
                    'partner_average_ticket' => [
                        'total_sum_prices' => (float) $sumPartnerDrink,
                        'total_orders' => (int) $countPartnerDrink,
                        'value' => $countPartnerDrink > 0 ? round($sumPartnerDrink / $countPartnerDrink, 2) : 0,
                    ],
                ]
            ];

            $fileName = 'TICKET-MOYEN-CROISÉ-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/cross_average_ticket';
            $filePath = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'start_date'       => $startDate?->format('d/m/Y'),
                'end_date'         => $endDate?->format('d/m/Y'),
                'menu_statistics'  => $menuStatistics,
                'drink_statistics' => $drinkStatistics,
            ];

            save_browser_shot_pdf(
                view: 'pdfs.cross_average_ticket.cross_average_ticket',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [5, 5, 5, 5],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'status'   => 'success',
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function topSellingItemsIndex(Request $request)
    {
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        } else {
            $firstItemDate = OrderMenuRestaurantItem::min('created_at');
            $firstDrinkDate = OrderRestaurantDrink::min('created_at');
            $firstCompDate = OrderMenuRestaurantItemComplement::min('created_at');

            $firstDate = collect([$firstItemDate, $firstDrinkDate, $firstCompDate])->filter()->sort()->first();

            $startDate = $firstDate
                ? Carbon::parse($firstDate)->startOfDay()
                : Carbon::minValue();
        }

        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        } else {
            $endDate = Carbon::yesterday()->endOfDay();
        }

        $paymentTypes = [
            TypeClientsForPaiment::DEBTOR->value,
            TypeClientsForPaiment::PARTNER->value
        ];

        $allTopMenus = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('menus_restaurant_uuid')
            ->whereHas('menu', fn($q) => $q->where('type_menu', [TypeMenu::MENU->value,TypeMenu::HOT_DRINK->value]))
            ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
            ->with(['menu'])
            ->get()
            ->groupBy('menus_restaurant_uuid')
            ->map(function ($items) {
                $first = $items->first();
                $menu = $first->menu;
                return [
                    'uuid' => $first->menus_restaurant_uuid,
                    'name' => $menu?->name ?? '',
                    'code' => $menu?->code ?? null,
                    'total_quantity' => (int) $items->sum('quantity_exactly'),
                    'total_revenue' => (float) $items->sum(fn($i) => ($i->unit_price ?? 0) * ($i->quantity_exactly ?? 0)),
                ];
            })
            ->filter(fn($item) => $item['total_quantity'] > 0 && $item['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        $allTopDrinks = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('drink_restaurant_uuid')
            ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
            ->with(['drinkConfig.product'])
            ->get()
            ->groupBy('drink_restaurant_uuid')
            ->map(function ($drinks) {
                $first = $drinks->first();
                $config = $first->drinkConfig;
                return [
                    'uuid' => $first->drink_restaurant_uuid,
                    'name' => $config?->product?->name ?? '',
                    'total_quantity' => (int) $drinks->sum('quantity_exactly'),
                    'total_revenue' => (float) $drinks->sum(fn($d) => ($d->unit_price ?? 0) * ($d->quantity_exactly ?? 0)),
                ];
            })
            ->filter(fn($drink) => $drink['total_quantity'] > 0 && $drink['total_revenue'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        $complementsList = collect();
        $drinksFromComplementsList = collect();

        $complementItems = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('menus_restaurant_uuid')
            ->whereHas('menu', fn($q) => $q->whereIn('type_menu', [TypeMenu::COMPLEMENT->value, TypeMenu::HOT_DRINK->value]))
            ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
            ->with(['menu'])
            ->get()
            ->groupBy('menus_restaurant_uuid');

        foreach ($complementItems as $uuid => $items) {
            $first = $items->first();
            $menu = $first->menu;
            $qty = (int) $items->sum('quantity_exactly');
            $rev = (float) $items->sum(fn($i) => ($i->unit_price ?? 0) * ($i->quantity_exactly ?? 0));

            if ($qty > 0) {
                $formatted = [
                    'uuid' => $uuid,
                    'name' => $menu?->name ?? '',
                    'code' => $menu?->code ?? null,
                    'total_quantity' => $qty,
                    'total_revenue' => $rev,
                ];
                if ($menu?->type_menu === TypeMenu::HOT_DRINK->value) {
                    $drinksFromComplementsList->push($formatted);
                } else {
                    $complementsList->push($formatted);
                }
            }
        }

        $tableComplements = OrderMenuRestaurantItemComplement::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('configuration_complement_uuid')
            ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
            ->with(['complement'])
            ->get()
            ->groupBy('configuration_complement_uuid');

        foreach ($tableComplements as $configUuid => $complements) {
            $first = $complements->first();
            $config = $first->complement;
            $qty = (int) $complements->sum('quantity');
            $rev = (float) $complements->sum(fn($c) => ($c->unit_price ?? 0) * ($c->quantity ?? 0));

            if ($qty > 0) {
                $formatted = [
                    'uuid' => $configUuid,
                    'name' => $config?->name ?? '',
                    'code' => $config?->code ?? null,
                    'total_quantity' => $qty,
                    'total_revenue' => $rev,
                ];

                if ($config?->menus_complement_type === 'boisson') {
                    $drinksFromComplementsList->push($formatted);
                } else {
                    $complementsList->push($formatted);
                }
            }
        }

        $formatGrouped = fn($collection) => $collection
            ->groupBy('uuid')
            ->map(fn($group) => [
                'uuid' => $group->first()['uuid'],
                'name' => $group->first()['name'],
                'code' => $group->first()['code'],
                'total_quantity' => $group->sum('total_quantity'),
                'total_revenue' => $group->sum('total_revenue'),
            ])
            ->filter(fn($item) => $item['total_quantity'] > 0)
            ->sortByDesc('total_quantity')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'top_menus' => $allTopMenus,
                'top_drinks' => $allTopDrinks,
                'top_complements' => $formatGrouped($complementsList),
                'top_drinks_from_complements' => $formatGrouped($drinksFromComplementsList),
            ]
        ]);
    }

    public function print_topSellingItemsIndex(Request $request)
    {
        try {
            if ($request->filled('start_date')) {
                $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            } else {
                $firstItemDate = OrderMenuRestaurantItem::min('created_at');
                $firstDrinkDate = OrderRestaurantDrink::min('created_at');
                $firstCompDate = OrderMenuRestaurantItemComplement::min('created_at');

                $firstDate = collect([$firstItemDate, $firstDrinkDate, $firstCompDate])->filter()->sort()->first();

                $startDate = $firstDate
                    ? Carbon::parse($firstDate)->startOfDay()
                    : Carbon::minValue();
            }

            if ($request->filled('end_date')) {
                $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
            } else {
                $endDate = Carbon::yesterday()->endOfDay();
            }

            $paymentTypes = [
                TypeClientsForPaiment::DEBTOR->value,
                TypeClientsForPaiment::PARTNER->value
            ];

            $allTopMenus = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('menus_restaurant_uuid')
                ->whereHas('menu', fn($q) => $q->where('type_menu', [TypeMenu::MENU->value, TypeMenu::HOT_DRINK->value]))
                ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
                ->with(['menu'])
                ->get()
                ->groupBy('menus_restaurant_uuid')
                ->map(function ($items) {
                    $first = $items->first();
                    $menu = $first->menu;
                    return [
                        'uuid' => $first->menus_restaurant_uuid,
                        'name' => $menu?->name ?? '',
                        'code' => $menu?->code ?? null,
                        'total_quantity' => (int) $items->sum('quantity_exactly'),
                        'total_revenue' => (float) $items->sum(fn($i) => ($i->unit_price ?? 0) * ($i->quantity_exactly ?? 0)),
                    ];
                })
                ->filter(fn($item) => $item['total_quantity'] > 0 && $item['total_revenue'] > 0)
                ->sortByDesc('total_quantity')
                ->values();

            $allTopDrinks = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('drink_restaurant_uuid')
                ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
                ->with(['drinkConfig.product'])
                ->get()
                ->groupBy('drink_restaurant_uuid')
                ->map(function ($drinks) {
                    $first = $drinks->first();
                    $config = $first->drinkConfig;
                    return [
                        'uuid' => $first->drink_restaurant_uuid,
                        'name' => $config?->product?->name ?? '',
                        'total_quantity' => (int) $drinks->sum('quantity_exactly'),
                        'total_revenue' => (float) $drinks->sum(fn($d) => ($d->unit_price ?? 0) * ($d->quantity_exactly ?? 0)),
                    ];
                })
                ->filter(fn($drink) => $drink['total_quantity'] > 0 && $drink['total_revenue'] > 0)
                ->sortByDesc('total_quantity')
                ->values();

            $complementsList = collect();
            $drinksFromComplementsList = collect();

            $complementItems = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('menus_restaurant_uuid')
                ->whereHas('menu', fn($q) => $q->whereIn('type_menu', [TypeMenu::COMPLEMENT->value, TypeMenu::HOT_DRINK->value]))
                ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
                ->with(['menu'])
                ->get()
                ->groupBy('menus_restaurant_uuid');

            foreach ($complementItems as $uuid => $items) {
                $first = $items->first();
                $menu = $first->menu;
                $qty = (int) $items->sum('quantity_exactly');
                $rev = (float) $items->sum(fn($i) => ($i->unit_price ?? 0) * ($i->quantity_exactly ?? 0));

                if ($qty > 0) {
                    $formatted = [
                        'uuid' => $uuid,
                        'name' => $menu?->name ?? '',
                        'code' => $menu?->code ?? null,
                        'total_quantity' => $qty,
                        'total_revenue' => $rev,
                    ];
                    if ($menu?->type_menu === TypeMenu::HOT_DRINK->value) {
                        $drinksFromComplementsList->push($formatted);
                    } else {
                        $complementsList->push($formatted);
                    }
                }
            }

            $tableComplements = OrderMenuRestaurantItemComplement::whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('configuration_complement_uuid')
                ->whereHas('order', fn($q) => $q->whereIn('type_clients_for_payment', $paymentTypes))
                ->with(['complement'])
                ->get()
                ->groupBy('configuration_complement_uuid');

            foreach ($tableComplements as $configUuid => $complements) {
                $first = $complements->first();
                $config = $first->complement;
                $qty = (int) $complements->sum('quantity');
                $rev = (float) $complements->sum(fn($c) => ($c->unit_price ?? 0) * ($c->quantity ?? 0));

                if ($qty > 0) {
                    $formatted = [
                        'uuid' => $configUuid,
                        'name' => $config?->name ?? '',
                        'code' => $config?->code ?? null,
                        'total_quantity' => $qty,
                        'total_revenue' => $rev,
                    ];

                    if ($config?->menus_complement_type === 'boisson') {
                        $drinksFromComplementsList->push($formatted);
                    } else {
                        $complementsList->push($formatted);
                    }
                }
            }

            $formatGrouped = fn($collection) => $collection
                ->groupBy('uuid')
                ->map(fn($group) => [
                    'uuid' => $group->first()['uuid'],
                    'name' => $group->first()['name'],
                    'code' => $group->first()['code'],
                    'total_quantity' => $group->sum('total_quantity'),
                    'total_revenue' => $group->sum('total_revenue'),
                ])
                ->filter(fn($item) => $item['total_quantity'] > 0)
                ->sortByDesc('total_quantity')
                ->values();

            $topComplements = $formatGrouped($complementsList);
            $topDrinksFromComplements = $formatGrouped($drinksFromComplementsList);

            $fileName = 'MENUS-BOISSONS-ET-COMPLÉMENTS-LES-PLUS-VENDUS-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/top_selling_items';
            $filePath = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'start_date' => $startDate->format('d/m/Y'),
                'end_date' => $endDate->format('d/m/Y'),
                'top_menus' => $allTopMenus,
                'top_drinks' => $allTopDrinks,
                'top_complements' => $topComplements,
                'top_drinks_from_complements' => $topDrinksFromComplements,
            ];

            save_browser_shot_pdf(
                view: 'pdfs.top_selling_items.top_selling_items',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'status'   => 'success',
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erreur génération PDF Top Ventes : ' . $e->getMessage() . ' ligne ' . $e->getLine());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF des articles les plus vendus',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function get_defective_statistics(Request $request)
    {
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        } else {
            $firstItemDate = OrderMenuRestaurantDefectiveItem::min('created_at');
            $firstDrinkDate = OrderMenuRestaurantDefectiveDrink::min('created_at');

            $firstDate = collect([$firstItemDate, $firstDrinkDate])->filter()->sort()->first();

            $startDate = $firstDate
                ? Carbon::parse($firstDate)->startOfDay()
                : now()->startOfDay();
        }
        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        } else {
            $endDate = now()->subDay()->endOfDay();
        }
        $userId = $request->input('created_by') ?? $request->input('user_id');


        $defectiveItems = OrderMenuRestaurantDefectiveItem::with([
            'item' => fn($query) => $query->withTrashed(),
            'item.menu'
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('is_deleted', true)
            ->when($userId, fn($query) => $query->where('created_by', $userId))
            ->get()
            ->filter(fn($defective) => $defective->item?->menu !== null);

        $processedMenus = collect();

        if ($defectiveItems->isNotEmpty()) {
            $menuUuids = $defectiveItems->map(fn($d) => $d->item->menus_restaurant_uuid)->unique()->filter()->values();

            $orderStatsPerMenu = OrderMenuRestaurantItem::withTrashed()
                ->whereIn('menus_restaurant_uuid', $menuUuids)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select(
                    'menus_restaurant_uuid',
                    DB::raw('COUNT(*) as total_orders_count'),
                    DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
                )
                ->groupBy('menus_restaurant_uuid')
                ->get()
                ->keyBy('menus_restaurant_uuid');

            $processedMenus = $defectiveItems->groupBy(fn($d) => $d->item->menus_restaurant_uuid)->map(function ($group) use ($orderStatsPerMenu) {
                $first = $group->first();
                $menuUuid = $first->item->menus_restaurant_uuid;
                $menu = $first->item->menu;

                $totalDefectiveQuantity = (int) $group->sum('quantity');
                $totalRevenue = (float) $group->sum(fn($def) => $def->quantity * ($def->item->unit_price ?? 0));

                $orderStats = $orderStatsPerMenu[$menuUuid] ?? null;
                $totalTimesOrdered = $orderStats ? (int) $orderStats->total_orders_count : 0;
                $currentOrderedQty = $orderStats ? (int) $orderStats->total_ordered_quantity : 0;
                $totalOrderedQuantity = $currentOrderedQty + $totalDefectiveQuantity;

                $defectiveRatio = $totalOrderedQuantity > 0
                    ? round(($totalDefectiveQuantity / $totalOrderedQuantity) * 100, 2)
                    : 0;

                return [
                    'uuid' => $menuUuid,
                    'name' => $menu->name,
                    'code' => $menu->code,
                    'type_menu' => $menu->type_menu,
                    'total_times_ordered' => $totalTimesOrdered,
                    'total_ordered_quantity' => $totalOrderedQuantity,
                    'total_defective_quantity' => $totalDefectiveQuantity,
                    'defective_ratio_percentage' => $defectiveRatio . '%',
                    'total_revenue_loss' => $totalRevenue,
                ];
            });
        }

        $topMenus = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::MENU->value)->sortByDesc('total_defective_quantity')->values();
        $topComplements = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::COMPLEMENT->value)->sortByDesc('total_defective_quantity')->values();
        $topDrinks = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::HOT_DRINK->value)->sortByDesc('total_defective_quantity')->values();


        $rawDefectiveDrinks = OrderMenuRestaurantDefectiveDrink::with([
            'drink' => fn($query) => $query->withTrashed(),
            'drink.drinkConfig'
        ])
            ->where('is_deleted', true)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($userId, fn($query) => $query->where('created_by', $userId))
            ->get();

        $defectiveDrinksItems = $rawDefectiveDrinks->filter(fn($def) => $def->drink?->drinkConfig !== null);
        $topOthersMenu = collect();

        if ($defectiveDrinksItems->isNotEmpty()) {
            $drinkConfigUuids = $defectiveDrinksItems->map(fn($def) => $def->drink->drink_restaurant_uuid)->unique()->filter()->values();

            $drinkOrderStats = OrderRestaurantDrink::withTrashed()
                ->whereIn('drink_restaurant_uuid', $drinkConfigUuids)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select(
                    'drink_restaurant_uuid',
                    DB::raw('COUNT(*) as total_orders_count'),
                    DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
                )
                ->groupBy('drink_restaurant_uuid')
                ->get()
                ->keyBy('drink_restaurant_uuid');

            $topOthersMenu = $defectiveDrinksItems->groupBy(fn($def) => $def->drink->drink_restaurant_uuid)->map(function ($group) use ($drinkOrderStats) {
                $first = $group->first();
                $drinkUuid = $first->drink->drink_restaurant_uuid;
                $drinkConfig = $first->drink->drinkConfig;

                $totalDefectiveQuantity = (int) $group->sum('quantity');
                $totalRevenue = (float) $group->sum(fn($def) => $def->quantity * ($def->drink->unit_price ?? 0));

                $stats = $drinkOrderStats[$drinkUuid] ?? null;
                $totalTimesOrdered = $stats ? (int) $stats->total_orders_count : 0;
                $currentOrderedQty = $stats ? (int) $stats->total_ordered_quantity : 0;
                $totalOrderedQuantity = $currentOrderedQty + $totalDefectiveQuantity;

                $defectiveRatio = $totalOrderedQuantity > 0
                    ? round(($totalDefectiveQuantity / $totalOrderedQuantity) * 100, 2)
                    : 0;

                return [
                    'uuid' => $drinkUuid,
                    'name' => $drinkConfig->display_name,
                    'code' => null,
                    'total_times_ordered' => $totalTimesOrdered,
                    'total_ordered_quantity' => $totalOrderedQuantity,
                    'total_defective_quantity' => $totalDefectiveQuantity,
                    'defective_ratio_percentage' => $defectiveRatio . '%',
                    'total_revenue_loss' => $totalRevenue,
                ];
            })->sortByDesc('total_defective_quantity')->values();
        }

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des pertes récupérées avec succès.',
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'top_defective_menus' => $topMenus,
                'top_defective_complements' => $topComplements,
                'top_defective_drinks' => $topDrinks,
                'top_defective_others_drinks' => $topOthersMenu,
            ]
        ]);
    }

    public function print_get_defective_statistics(Request $request)
    {
        try {
            if ($request->filled('start_date')) {
                $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            } else {
                $firstItemDate = OrderMenuRestaurantDefectiveItem::min('created_at');
                $firstDrinkDate = OrderMenuRestaurantDefectiveDrink::min('created_at');

                $firstDate = collect([$firstItemDate, $firstDrinkDate])->filter()->sort()->first();

                $startDate = $firstDate
                    ? Carbon::parse($firstDate)->startOfDay()
                    : now()->startOfDay();
            }

            if ($request->filled('end_date')) {
                $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
            } else {
                $endDate = now()->subDay()->endOfDay();
            }

            $userId = $request->input('created_by') ?? $request->input('user_id');

            $defectiveItems = OrderMenuRestaurantDefectiveItem::with([
                'item' => fn($query) => $query->withTrashed(),
                'item.menu'
            ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('is_deleted', true)
                ->when($userId, fn($query) => $query->where('created_by', $userId))
                ->get()
                ->filter(fn($defective) => $defective->item?->menu !== null);

            $processedMenus = collect();

            if ($defectiveItems->isNotEmpty()) {
                $menuUuids = $defectiveItems->map(fn($d) => $d->item->menus_restaurant_uuid)->unique()->filter()->values();

                $orderStatsPerMenu = OrderMenuRestaurantItem::withTrashed()
                    ->whereIn('menus_restaurant_uuid', $menuUuids)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select(
                        'menus_restaurant_uuid',
                        DB::raw('COUNT(*) as total_orders_count'),
                        DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
                    )
                    ->groupBy('menus_restaurant_uuid')
                    ->get()
                    ->keyBy('menus_restaurant_uuid');

                $processedMenus = $defectiveItems->groupBy(fn($d) => $d->item->menus_restaurant_uuid)->map(function ($group) use ($orderStatsPerMenu) {
                    $first = $group->first();
                    $menuUuid = $first->item->menus_restaurant_uuid;
                    $menu = $first->item->menu;

                    $totalDefectiveQuantity = (int) $group->sum('quantity');
                    $totalRevenue = (float) $group->sum(fn($def) => $def->quantity * ($def->item->unit_price ?? 0));

                    $orderStats = $orderStatsPerMenu[$menuUuid] ?? null;
                    $totalTimesOrdered = $orderStats ? (int) $orderStats->total_orders_count : 0;
                    $currentOrderedQty = $orderStats ? (int) $orderStats->total_ordered_quantity : 0;
                    $totalOrderedQuantity = $currentOrderedQty + $totalDefectiveQuantity;

                    $defectiveRatio = $totalOrderedQuantity > 0
                        ? round(($totalDefectiveQuantity / $totalOrderedQuantity) * 100, 2)
                        : 0;

                    return [
                        'uuid' => $menuUuid,
                        'name' => $menu->name,
                        'code' => $menu->code,
                        'type_menu' => $menu->type_menu,
                        'total_times_ordered' => $totalTimesOrdered,
                        'total_ordered_quantity' => $totalOrderedQuantity,
                        'total_defective_quantity' => $totalDefectiveQuantity,
                        'defective_ratio_percentage' => $defectiveRatio . '%',
                        'total_revenue_loss' => $totalRevenue,
                    ];
                });
            }

            $topMenus = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::MENU->value)->sortByDesc('total_defective_quantity')->values();
            $topComplements = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::COMPLEMENT->value)->sortByDesc('total_defective_quantity')->values();
            $topDrinks = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::HOT_DRINK->value)->sortByDesc('total_defective_quantity')->values();

            $rawDefectiveDrinks = OrderMenuRestaurantDefectiveDrink::with([
                'drink' => fn($query) => $query->withTrashed(),
                'drink.drinkConfig'
            ])
                ->where('is_deleted', true)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($userId, fn($query) => $query->where('created_by', $userId))
                ->get();

            $defectiveDrinksItems = $rawDefectiveDrinks->filter(fn($def) => $def->drink?->drinkConfig !== null);
            $topOthersMenu = collect();

            if ($defectiveDrinksItems->isNotEmpty()) {
                $drinkConfigUuids = $defectiveDrinksItems->map(fn($def) => $def->drink->drink_restaurant_uuid)->unique()->filter()->values();

                $drinkOrderStats = OrderRestaurantDrink::withTrashed()
                    ->whereIn('drink_restaurant_uuid', $drinkConfigUuids)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select(
                        'drink_restaurant_uuid',
                        DB::raw('COUNT(*) as total_orders_count'),
                        DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
                    )
                    ->groupBy('drink_restaurant_uuid')
                    ->get()
                    ->keyBy('drink_restaurant_uuid');

                $topOthersMenu = $defectiveDrinksItems->groupBy(fn($def) => $def->drink->drink_restaurant_uuid)->map(function ($group) use ($drinkOrderStats) {
                    $first = $group->first();
                    $drinkUuid = $first->drink->drink_restaurant_uuid;
                    $drinkConfig = $first->drink->drinkConfig;

                    $totalDefectiveQuantity = (int) $group->sum('quantity');
                    $totalRevenue = (float) $group->sum(fn($def) => $def->quantity * ($def->drink->unit_price ?? 0));

                    $stats = $drinkOrderStats[$drinkUuid] ?? null;
                    $totalTimesOrdered = $stats ? (int) $stats->total_orders_count : 0;
                    $currentOrderedQty = $stats ? (int) $stats->total_ordered_quantity : 0;
                    $totalOrderedQuantity = $currentOrderedQty + $totalDefectiveQuantity;

                    $defectiveRatio = $totalOrderedQuantity > 0
                        ? round(($totalDefectiveQuantity / $totalOrderedQuantity) * 100, 2)
                        : 0;

                    return [
                        'uuid' => $drinkUuid,
                        'name' => $drinkConfig->display_name,
                        'code' => null,
                        'total_times_ordered' => $totalTimesOrdered,
                        'total_ordered_quantity' => $totalOrderedQuantity,
                        'total_defective_quantity' => $totalDefectiveQuantity,
                        'defective_ratio_percentage' => $defectiveRatio . '%',
                        'total_revenue_loss' => $totalRevenue,
                    ];
                })->sortByDesc('total_defective_quantity')->values();
            }

            $fileName = 'STATISTIQUES-DES-PERTES-(MENUS DÉFECTUEUX)-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/cross_average_ticket';
            $filePath = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'start_date' => $startDate->format('d/m/Y'),
                'end_date' => $endDate->format('d/m/Y'),
                'top_defective_menus' => $topMenus,
                'top_defective_complements' => $topComplements,
                'top_defective_drinks' => $topDrinks,
                'top_defective_others_drinks' => $topOthersMenu,
            ];

            save_browser_shot_pdf(
                view: 'pdfs.losses_and_offers.losses_and_offers',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [5, 5, 5, 5],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'status'   => 'success',
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erreur génération PDF Statistiques Pertes : ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function clientConsumptionHistory(Request $request)
    {
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        } else {
            $firstOrderDate = OrderMenuRestaurant::min('created_at');

            $startDate = $firstOrderDate
                ? Carbon::parse($firstOrderDate)->startOfDay()
                : Carbon::minValue();
        }

        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        } else {
            $endDate = Carbon::yesterday()->endOfDay();
        }

        $query = OrderMenuRestaurant::query()
            ->whereIn('type_clients_for_payment', [
                TypeClientsForPaiment::PARTNER->value
            ])
            ->whereHas('partners_restaurant')
            ->whereBetween('created_at', [$startDate, $endDate]);

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

    public function print_clientConsumptionHistory(Request $request)
    {
        try {
            if ($request->filled('start_date')) {
                $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            } else {
                $firstOrderDate = OrderMenuRestaurant::min('created_at');

                $startDate = $firstOrderDate
                    ? Carbon::parse($firstOrderDate)->startOfDay()
                    : Carbon::minValue();
            }

            // Date de fin : par défaut jusqu'à la journée N-1 (hier)
            if ($request->filled('end_date')) {
                $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
            } else {
                $endDate = Carbon::yesterday()->endOfDay();
            }

            $query = OrderMenuRestaurant::query()
                ->whereIn('type_clients_for_payment', [
                    TypeClientsForPaiment::PARTNER->value
                ])
                ->whereHas('partners_restaurant')
                ->whereBetween('created_at', [$startDate, $endDate]);

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

            $fileName = 'Historique-de-consommation-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/sales_history_by_client';
            $filePath = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'start_date' => $startDate->format('d/m/Y'),
                'end_date' => $endDate->format('d/m/Y'),
                'clients' => $clientHistory,
            ];

            save_browser_shot_pdf(
                view: 'pdfs.sales_history_by_client.sales_history_by_client',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [5, 5, 5, 5],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'status'   => 'success',
                'data'     => $clientHistory,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erreur génération PDF Historique de consommation : ' . $e->getMessage() . ' ligne ' . $e->getLine());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF de l\'historique de consommation',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}

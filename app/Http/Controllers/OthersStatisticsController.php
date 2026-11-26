<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Enums\TypeMenu;
use App\Models\ConfigurationsComplement;
use App\Models\DeletedOrderMenuRestaurantItem;
use App\Models\DeletedOrderRestaurantDrink;
use App\Models\FreeClientRestaurant;
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
        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

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
        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

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
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

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
        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

        $paymentTypes = [
            TypeClientsForPaiment::DEBTOR->value,
            TypeClientsForPaiment::PARTNER->value,
            TypeClientsForPaiment::FREE->value
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
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

            $paymentTypes = [
                TypeClientsForPaiment::DEBTOR->value,
                TypeClientsForPaiment::PARTNER->value,
                TypeClientsForPaiment::FREE->value
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
        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        $userId = $request->input('created_by') ?? $request->input('user_id');

        $menuUuidFilter = $request->input('menu_uuid');
        $drinkUuidFilter = $request->input('drink_uuid');

        $defectiveItems = OrderMenuRestaurantDefectiveItem::with([
            'item' => fn($query) => $query->withTrashed(),
            'item.menu',
            'creator' => fn($query) => $query->withTrashed()
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('is_deleted', true)
            ->when($userId, fn($query) => $query->where('created_by', $userId))
            ->when($menuUuidFilter, function($query) use ($menuUuidFilter) {
                $query->whereHas('item', function($q) use ($menuUuidFilter) {
                    $q->withTrashed()->where('menus_restaurant_uuid', $menuUuidFilter);
                });
            })
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

            // Regroupement par Menu ET par Utilisateur (créateur)
            $processedMenus = $defectiveItems->groupBy(function ($d) {
                return $d->item->menus_restaurant_uuid . '_' . $d->created_by;
            })->map(function ($group) use ($orderStatsPerMenu) {
                $first = $group->first();
                $menuUuid = $first->item->menus_restaurant_uuid;
                $menu = $first->item->menu;
                $user = $first->creator; // Utilisation de la relation 'creator'

                $totalDefectiveQuantity = (int) $group->sum('quantity');

                // Calcul du revenu / perte
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
                    'user_id' => $user?->id ?? $first->created_by,
                    'user_name' => $user?->nom_utilisateur ?? 'Inconnu',
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


        // 2. Utilisation de 'creator' pour les boissons défectueuses également
        $rawDefectiveDrinks = OrderMenuRestaurantDefectiveDrink::with([
            'drink' => fn($query) => $query->withTrashed(),
            'drink.drinkConfig',
            'creator' => fn($query) => $query->withTrashed()
        ])
            ->where('is_deleted', true)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($userId, fn($query) => $query->where('created_by', $userId))
            ->when($drinkUuidFilter, function($query) use ($drinkUuidFilter) {
                $query->whereHas('drink', function($q) use ($drinkUuidFilter) {
                    $q->withTrashed()->where('drink_restaurant_uuid', $drinkUuidFilter);
                });
            })
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

            // Regroupement par Boisson ET par Utilisateur
            $topOthersMenu = $defectiveDrinksItems->groupBy(function ($def) {
                return $def->drink->drink_restaurant_uuid . '_' . $def->created_by;
            })->map(function ($group) use ($drinkOrderStats) {
                $first = $group->first();
                $drinkUuid = $first->drink->drink_restaurant_uuid;
                $drinkConfig = $first->drink->drinkConfig;
                $user = $first->creator; // Utilisation de la relation 'creator'

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
                    'user_id' => $user?->id ?? $first->created_by,
                    'user_name' => $user?->nom_utilisateur ?? 'Inconnu',
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
    public function get_deleted_statistics(Request $request)
    {
        $userId = $request->input('deleted_by') ?? $request->input('created_by') ?? $request->input('user_id');
        $menuUuidFilter = $request->input('menu_uuid');
        $drinkUuidFilter = $request->input('drink_uuid');

        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

        $query = DeletedOrderMenuRestaurantItem::with([
            'menu',
            'deletedBy',
        ])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($userId, fn($q) => $q->where('deleted_by', $userId))
            ->when($menuUuidFilter, fn($q) => $q->where('menus_restaurant_uuid', $menuUuidFilter));

        $rawDeletedItems = $query->get();

        $defectiveItems = $rawDeletedItems->filter(fn($defective) => $defective->menu !== null);

        $processedMenus = collect();

        if ($defectiveItems->isNotEmpty()) {
            $menuUuids = $defectiveItems->pluck('menus_restaurant_uuid')->unique()->filter()->values();

            $orderStatsPerMenu = OrderMenuRestaurantItem::whereIn('menus_restaurant_uuid', $menuUuids)
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->select(
                    'menus_restaurant_uuid',
                    DB::raw('COUNT(*) as total_orders_count'),
                    DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
                )
                ->groupBy('menus_restaurant_uuid')
                ->get()
                ->keyBy('menus_restaurant_uuid');

            $processedMenus = $defectiveItems->groupBy(function ($d) {
                return $d->menus_restaurant_uuid . '_' . $d->deleted_by;
            })->map(function ($group) use ($orderStatsPerMenu) {
                $first = $group->first();
                $menuUuid = $first->menus_restaurant_uuid;
                $menu = $first->menu;
                $user = $first->deletedBy;

                $totalDefectiveQuantity = (int) $group->sum('quantity_ordered');
                $totalRevenue = (float) $group->sum(fn($def) => $def->quantity_ordered * ($def->unit_price ?? 0));

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
                    'user_id' => $user?->id ?? $first->deleted_by,
                    'user_name' => $user?->nom_utilisateur ?? 'Inconnu',
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

        $rawDefectiveDrinks = DeletedOrderRestaurantDrink::with([
            'drinkConfig',
            'deleter',
        ])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($userId, fn($q) => $q->where('deleted_by', $userId))
            ->when($drinkUuidFilter, fn($q) => $q->where('drink_restaurant_uuid', $drinkUuidFilter))
            ->get();

        $defectiveDrinksItems = $rawDefectiveDrinks->filter(fn($def) => $def->drinkConfig !== null);
        $topOthersMenu = collect();

        if ($defectiveDrinksItems->isNotEmpty()) {
            $drinkConfigUuids = $defectiveDrinksItems->pluck('drink_restaurant_uuid')->unique()->filter()->values();

            $drinkOrderStats = OrderRestaurantDrink::whereIn('drink_restaurant_uuid', $drinkConfigUuids)
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->select(
                    'drink_restaurant_uuid',
                    DB::raw('COUNT(*) as total_orders_count'),
                    DB::raw('SUM(quantity_exactly) as total_ordered_quantity')
                )
                ->groupBy('drink_restaurant_uuid')
                ->get()
                ->keyBy('drink_restaurant_uuid');

            $topOthersMenu = $defectiveDrinksItems->groupBy(function ($def) {
                return $def->drink_restaurant_uuid . '_' . $def->deleted_by;
            })->map(function ($group) use ($drinkOrderStats) {
                $first = $group->first();
                $drinkUuid = $first->drink_restaurant_uuid;
                $drinkConfig = $first->drinkConfig;
                $user = $first->deleter;

                $totalDefectiveQuantity = (int) $group->sum('quantity_ordered');
                $totalRevenue = (float) $group->sum(fn($def) => $def->quantity_ordered * ($def->unit_price ?? 0));

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
                    'user_id' => $user?->id ?? $first->deleted_by,
                    'user_name' => $user?->nom_utilisateur ?? 'Inconnu',
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
            'message' => 'Statistiques des suppressions récupérées avec succès.',
            'data' => [
                'start_date' => $startDate?->format('d-m-Y'),
                'end_date' => $endDate?->format('d-m-Y'),
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
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

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
        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

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
            $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
            $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();

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

    public function get_free_statistics(Request $request)
    {
        $startDate = Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay();
        $endDate = Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay();
        $userId = $request->input('created_by') ?? $request->input('user_id');

        $menuUuidFilter = $request->input('menu_uuid');
        $drinkUuidFilter = $request->input('drink_uuid');
        $freeClientUuidFilter = $request->input('free_client_uuid') ?? $request->input('free_client_for_restaurant_uuid');

        $paymentTypes = [
            \App\Enums\TypeClientsForPaiment::FREE->value,
        ];

        $applyOrderFilters = function ($q) use ($paymentTypes, $freeClientUuidFilter) {
            $q->whereIn('type_clients_for_payment', $paymentTypes)
                ->when($freeClientUuidFilter, fn($query) => $query->where('free_client_for_restaurant_uuid', $freeClientUuidFilter));
        };

        $resolveFullName = function ($freeClient): ?string {
            if (!$freeClient) {
                return null;
            }

            if (!empty($freeClient->full_name)) {
                return $freeClient->full_name;
            }

            $combined = trim(($freeClient->first_name ?? '') . ' ' . ($freeClient->last_name ?? ''));
            return $combined !== '' ? $combined : null;
        };

        /**
         * Calcule le ratio (quantité / occurrences) et les parts (%) sur tous les éléments.
         * Si $negativeRatio = true (menus), le ratio est négatif.
         */
        $computeRatioAndShares = function (
            int $qty,
            int $timesOrdered,
            float $salePrice,
            float $prodCost,
            int $globalQty,
            float $globalSalePrice,
            float $globalProdCost,
            bool $negativeRatio = false
        ): array {
            $sign = $negativeRatio ? -1 : 1;
            $baseRatio = $timesOrdered > 0 ? ($qty / $timesOrdered) : 0;
            $ratio = round($sign * $baseRatio, 4);

            $quantityShare = $globalQty > 0 ? round(($qty / $globalQty) * 100, 2) : 0;
            $saleShare = $globalSalePrice > 0 ? round(($salePrice / $globalSalePrice) * 100, 2) : 0;
            $costShare = $globalProdCost > 0 ? round(($prodCost / $globalProdCost) * 100, 2) : 0;

            // Pourcentage basé sur la part de quantité (ou 0 si aucune donnée), négatif sur les menus
            $ratioPercentage = $globalQty > 0 ? round($sign * (($qty / $globalQty) * 100), 2) : 0;

            return [
                'ratio' => $ratio,
                'ratio_percentage' => $ratioPercentage,
                'quantity_share_percentage' => $quantityShare,
                'sale_share_percentage' => $saleShare,
                'cost_share_percentage' => $costShare,
            ];
        };

        $makeRubriqueBucket = fn(string $key, string $label, bool $negativeRatio = false): array => [
            'rubrique' => $key,
            'label' => $label,
            'negative_ratio' => $negativeRatio,
            'times_ordered' => 0,
            'ordered_quantity' => 0,
            'total_sale_price' => 0.0,
            'total_revenue_loss' => 0.0,
            'total_production_cost' => 0.0,
            'global_production_cost' => 0.0,
        ];

        $freeItems = OrderMenuRestaurantItem::with([
            'menu' => fn($query) => $query->withTrashed(),
            'creator' => fn($query) => $query->withTrashed(),
            'order.free_client_for_restaurant',
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', $applyOrderFilters)
            ->when($userId, fn($query) => $query->where('created_by', $userId))
            ->when($menuUuidFilter, function ($query) use ($menuUuidFilter) {
                $query->where('menus_restaurant_uuid', $menuUuidFilter);
            })
            ->get()
            ->filter(fn($item) => $item->menu !== null);

        $rawFreeDrinks = OrderRestaurantDrink::with([
            'drinkConfig' => fn($query) => $query->withTrashed(),
            'creator' => fn($query) => $query->withTrashed(),
            'order.free_client_for_restaurant',
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', $applyOrderFilters)
            ->when($userId, fn($query) => $query->where('created_by', $userId))
            ->when($drinkUuidFilter, function ($query) use ($drinkUuidFilter) {
                $query->where('drink_restaurant_uuid', $drinkUuidFilter);
            })
            ->get();

        $freeDrinksItems = $rawFreeDrinks->filter(fn($def) => $def->drinkConfig !== null);

        // Totaux globaux de référence sur la période
        $globalTotalQty = (int) $freeItems->sum(fn($i) => (int) ($i->quantity_exactly ?? 0))
            + (int) $freeDrinksItems->sum(fn($d) => (int) ($d->quantity_exactly ?? 0));

        $globalTotalTimesOrdered = $freeItems->count() + $freeDrinksItems->count();

        $globalTotalSalePrice = (float) $freeItems->sum(function ($item) {
                $qty = (int) ($item->quantity_exactly ?? 0);
                $price = (float) ($item->unit_price ?? $item->menu->price ?? $item->menu->sale_price ?? 0);
                return $qty * $price;
            }) + (float) $freeDrinksItems->sum(function ($drinkItem) {
                $qty = (int) ($drinkItem->quantity_exactly ?? 0);
                $price = (float) ($drinkItem->unit_price ?? $drinkItem->drinkConfig->price ?? $drinkItem->drinkConfig->sale_price ?? 0);
                return $qty * $price;
            });

        $globalTotalProdCost = (float) $freeItems->sum(function ($item) {
                $qty = (int) ($item->quantity_exactly ?? 0);
                $unitCost = (float) ($item->menu->calculated_production_cost ?? $item->menu->production_cost_final ?? $item->menu->production_cost ?? 0);
                return $qty * $unitCost;
            }) + (float) $freeDrinksItems->sum(function ($drinkItem) {
                $qty = (int) ($drinkItem->quantity_exactly ?? 0);
                $unitCost = (float) ($drinkItem->drinkConfig->calculated_production_cost ?? $drinkItem->drinkConfig->production_cost_final ?? $drinkItem->drinkConfig->production_cost ?? 0);
                return $qty * $unitCost;
            });

        $processedMenus = collect();
        $clientTotalsMap = [];

        $registerClientRubrique = function (
            ?string $clientUuid,
            ?string $fullName,
            string $rubriqueKey,
            string $rubriqueLabel,
            bool $negativeRatio,
            int $qty,
            int $timesOrdered,
            float $salePrice,
            float $productionCost,
            float $globalProdCost
        ) use (&$clientTotalsMap, $makeRubriqueBucket) {
            $key = $clientUuid ?? '__unknown__';

            if (!isset($clientTotalsMap[$key])) {
                $clientTotalsMap[$key] = [
                    'free_client_uuid' => $clientUuid,
                    'full_name' => $fullName ?: '',
                    'total_times_ordered' => 0,
                    'total_ordered_quantity' => 0,
                    'total_sale_price' => 0.0,
                    'total_revenue_loss' => 0.0,
                    'total_production_cost' => 0.0,
                    'global_production_cost' => 0.0,
                    'rubriques_map' => [
                        'menus' => $makeRubriqueBucket('menus', 'Menus', true),
                        'complements' => $makeRubriqueBucket('complements', 'Compléments', false),
                        'hot_drinks' => $makeRubriqueBucket('hot_drinks', 'Boissons chaudes', false),
                        'drinks' => $makeRubriqueBucket('drinks', 'Boissons', false),
                    ],
                ];
            } elseif (empty($clientTotalsMap[$key]['full_name']) && !empty($fullName)) {
                $clientTotalsMap[$key]['full_name'] = $fullName;
            }

            $clientTotalsMap[$key]['total_times_ordered'] += $timesOrdered;
            $clientTotalsMap[$key]['total_ordered_quantity'] += $qty;
            $clientTotalsMap[$key]['total_sale_price'] += $salePrice;
            $clientTotalsMap[$key]['total_revenue_loss'] += $salePrice;
            $clientTotalsMap[$key]['total_production_cost'] += $productionCost;
            $clientTotalsMap[$key]['global_production_cost'] += $globalProdCost;

            if (!isset($clientTotalsMap[$key]['rubriques_map'][$rubriqueKey])) {
                $clientTotalsMap[$key]['rubriques_map'][$rubriqueKey] = $makeRubriqueBucket(
                    $rubriqueKey,
                    $rubriqueLabel,
                    $negativeRatio
                );
            }

            $bucket = &$clientTotalsMap[$key]['rubriques_map'][$rubriqueKey];
            $bucket['times_ordered'] += $timesOrdered;
            $bucket['ordered_quantity'] += $qty;
            $bucket['total_sale_price'] += $salePrice;
            $bucket['total_revenue_loss'] += $salePrice;
            $bucket['total_production_cost'] += $productionCost;
            $bucket['global_production_cost'] += $globalProdCost;
        };

        if ($freeItems->isNotEmpty()) {
            foreach ($freeItems as $item) {
                $order = $item->order;
                $freeClient = $order?->free_client_for_restaurant;
                $clientUuid = $order?->free_client_for_restaurant_uuid;
                $fullName = $resolveFullName($freeClient);

                $qty = (int) ($item->quantity_exactly ?? 0);
                $unitSalePrice = (float) ($item->unit_price ?? $item->menu->price ?? $item->menu->sale_price ?? 0);
                $salePrice = (float) ($qty * $unitSalePrice);
                $unitProdCost = (float) ($item->menu->calculated_production_cost ?? $item->menu->production_cost_final ?? $item->menu->production_cost ?? 0);
                $prodCost = (float) ($qty * $unitProdCost);
                $globalProdCost = (float) ($salePrice - $prodCost);

                [$rubriqueKey, $rubriqueLabel, $negativeRatio] = match ($item->menu->type_menu) {
                    \App\Enums\TypeMenu::MENU->value => ['menus', 'Menus', true],
                    \App\Enums\TypeMenu::COMPLEMENT->value => ['complements', 'Compléments', false],
                    \App\Enums\TypeMenu::HOT_DRINK->value => ['hot_drinks', 'Boissons chaudes', false],
                    default => ['menus', 'Menus', true],
                };

                $registerClientRubrique(
                    $clientUuid,
                    $fullName,
                    $rubriqueKey,
                    $rubriqueLabel,
                    $negativeRatio,
                    $qty,
                    1,
                    $salePrice,
                    $prodCost,
                    $globalProdCost
                );
            }

            $processedMenus = $freeItems->groupBy('menus_restaurant_uuid')->map(function ($group) use (
                $resolveFullName,
                $computeRatioAndShares,
                $globalTotalQty,
                $globalTotalSalePrice,
                $globalTotalProdCost
            ) {
                $first = $group->first();
                $menuUuid = $first->menus_restaurant_uuid;
                $menu = $first->menu;
                $user = $first->creator;
                $freeClient = $first->order?->free_client_for_restaurant;

                $totalQuantity = (int) $group->sum('quantity_exactly');
                $unitSalePrice = (float) ($first->unit_price ?? $menu->price ?? $menu->sale_price ?? 0);
                $totalSalePrice = (float) $group->sum(function ($item) use ($menu) {
                    $price = (float) ($item->unit_price ?? $menu->price ?? $menu->sale_price ?? 0);
                    return (int) ($item->quantity_exactly ?? 0) * $price;
                });
                $totalTimesOrdered = $group->count();

                $calculatedProductionCost = (float) ($menu->calculated_production_cost ?? $menu->production_cost_final ?? $menu->production_cost ?? 0);
                $totalProductionCost = (float) ($totalQuantity * $calculatedProductionCost);
                $globalProductionCost = (float) ($totalSalePrice - $totalProductionCost);

                $isMenu = $menu->type_menu === \App\Enums\TypeMenu::MENU->value;
                $metrics = $computeRatioAndShares(
                    $totalQuantity,
                    $totalTimesOrdered,
                    $totalSalePrice,
                    $totalProductionCost,
                    $globalTotalQty,
                    $globalTotalSalePrice,
                    $globalTotalProdCost,
                    $isMenu
                );

                return [
                    'uuid' => $menuUuid,
                    'name' => $menu->name,
                    'code' => $menu->code,
                    'type_menu' => $menu->type_menu,
                    'unit_price' => round($unitSalePrice, 2),
                    'total_sale_price' => round($totalSalePrice, 2),
                    'free_client_uuid' => $first->order?->free_client_for_restaurant_uuid,
                    'free_client_full_name' => $resolveFullName($freeClient),
                    'user_id' => $user?->id ?? $first->created_by,
                    'user_name' => $user?->nom_utilisateur ?? 'Inconnu',
                    'total_times_ordered' => $totalTimesOrdered,
                    'total_ordered_quantity' => $totalQuantity,
                    'total_revenue_loss' => round($totalSalePrice, 2),
                    'calculated_production_cost' => round($calculatedProductionCost, 2),
                    'total_production_cost' => round($totalProductionCost, 2),
                    'global_production_cost' => round($globalProductionCost, 2),
                    'ratio' => $metrics['ratio'],
                    'ratio_percentage' => $metrics['ratio_percentage'],
                    'quantity_share_percentage' => $metrics['quantity_share_percentage'],
                    'sale_share_percentage' => $metrics['sale_share_percentage'],
                    'cost_share_percentage' => $metrics['cost_share_percentage'],
                ];
            });
        }

        $topMenus = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::MENU->value)->sortByDesc('total_ordered_quantity')->values();
        $topComplements = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::COMPLEMENT->value)->sortByDesc('total_ordered_quantity')->values();
        $topDrinks = $processedMenus->filter(fn($item) => $item['type_menu'] === \App\Enums\TypeMenu::HOT_DRINK->value)->sortByDesc('total_ordered_quantity')->values();

        $topOthersMenu = collect();

        if ($freeDrinksItems->isNotEmpty()) {
            foreach ($freeDrinksItems as $drinkItem) {
                $order = $drinkItem->order;
                $freeClient = $order?->free_client_for_restaurant;
                $clientUuid = $order?->free_client_for_restaurant_uuid;
                $fullName = $resolveFullName($freeClient);

                $qty = (int) ($drinkItem->quantity_exactly ?? 0);
                $unitSalePrice = (float) ($drinkItem->unit_price ?? $drinkItem->drinkConfig->price ?? $drinkItem->drinkConfig->sale_price ?? 0);
                $salePrice = (float) ($qty * $unitSalePrice);
                $unitProdCost = (float) ($drinkItem->drinkConfig->calculated_production_cost ?? $drinkItem->drinkConfig->production_cost_final ?? $drinkItem->drinkConfig->production_cost ?? 0);
                $prodCost = (float) ($qty * $unitProdCost);
                $globalProdCost = (float) ($salePrice - $prodCost);

                $registerClientRubrique(
                    $clientUuid,
                    $fullName,
                    'drinks',
                    'Boissons',
                    false,
                    $qty,
                    1,
                    $salePrice,
                    $prodCost,
                    $globalProdCost
                );
            }

            $topOthersMenu = $freeDrinksItems->groupBy('drink_restaurant_uuid')->map(function ($group) use (
                $resolveFullName,
                $computeRatioAndShares,
                $globalTotalQty,
                $globalTotalSalePrice,
                $globalTotalProdCost
            ) {
                $first = $group->first();
                $drinkUuid = $first->drink_restaurant_uuid;
                $drinkConfig = $first->drinkConfig;
                $user = $first->creator;
                $freeClient = $first->order?->free_client_for_restaurant;

                $totalQuantity = (int) $group->sum('quantity_exactly');
                $unitSalePrice = (float) ($first->unit_price ?? $drinkConfig->price ?? $drinkConfig->sale_price ?? 0);
                $totalSalePrice = (float) $group->sum(function ($def) use ($drinkConfig) {
                    $price = (float) ($def->unit_price ?? $drinkConfig->price ?? $drinkConfig->sale_price ?? 0);
                    return (int) ($def->quantity_exactly ?? 0) * $price;
                });
                $totalTimesOrdered = $group->count();

                $calculatedProductionCost = (float) ($drinkConfig->calculated_production_cost ?? $drinkConfig->production_cost_final ?? $drinkConfig->production_cost ?? 0);
                $totalProductionCost = (float) ($totalQuantity * $calculatedProductionCost);
                $globalProductionCost = (float) ($totalSalePrice - $totalProductionCost);

                $metrics = $computeRatioAndShares(
                    $totalQuantity,
                    $totalTimesOrdered,
                    $totalSalePrice,
                    $totalProductionCost,
                    $globalTotalQty,
                    $globalTotalSalePrice,
                    $globalTotalProdCost,
                    false
                );

                return [
                    'uuid' => $drinkUuid,
                    'name' => $drinkConfig->display_name,
                    'code' => null,
                    'unit_price' => round($unitSalePrice, 2),
                    'total_sale_price' => round($totalSalePrice, 2),
                    'free_client_uuid' => $first->order?->free_client_for_restaurant_uuid,
                    'free_client_full_name' => $resolveFullName($freeClient),
                    'user_id' => $user?->id ?? $first->created_by,
                    'user_name' => $user?->nom_utilisateur ?? 'Inconnu',
                    'total_times_ordered' => $totalTimesOrdered,
                    'total_ordered_quantity' => $totalQuantity,
                    'total_revenue_loss' => round($totalSalePrice, 2),
                    'calculated_production_cost' => round($calculatedProductionCost, 2),
                    'total_production_cost' => round($totalProductionCost, 2),
                    'global_production_cost' => round($globalProductionCost, 2),
                    'ratio' => $metrics['ratio'],
                    'ratio_percentage' => $metrics['ratio_percentage'],
                    'quantity_share_percentage' => $metrics['quantity_share_percentage'],
                    'sale_share_percentage' => $metrics['sale_share_percentage'],
                    'cost_share_percentage' => $metrics['cost_share_percentage'],
                ];
            })->sortByDesc('total_ordered_quantity')->values();
        }

        $freeClientsSummary = collect($clientTotalsMap)->map(function ($entry) use (
            $computeRatioAndShares,
            $globalTotalQty,
            $globalTotalSalePrice,
            $globalTotalProdCost
        ) {
            $qty = (int) $entry['total_ordered_quantity'];
            $times = (int) $entry['total_times_ordered'];
            $totalClientSalePrice = (float) $entry['total_sale_price'];
            $totalClientProdCost = (float) $entry['total_production_cost'];

            $rubriques = collect($entry['rubriques_map'])->map(function ($bucket) use (
                $qty,
                $totalClientSalePrice,
                $totalClientProdCost,
                $computeRatioAndShares
            ) {
                $bQty = (int) $bucket['ordered_quantity'];
                $bTimes = (int) $bucket['times_ordered'];
                $isNegative = !empty($bucket['negative_ratio']);

                $metrics = $computeRatioAndShares(
                    $bQty,
                    $bTimes,
                    (float) $bucket['total_sale_price'],
                    (float) $bucket['total_production_cost'],
                    $qty,
                    $totalClientSalePrice,
                    $totalClientProdCost,
                    $isNegative
                );

                return [
                    'rubrique' => $bucket['rubrique'],
                    'label' => $bucket['label'],
                    'times_ordered' => $bTimes,
                    'ordered_quantity' => $bQty,
                    'total_sale_price' => round($bucket['total_sale_price'], 2),
                    'total_revenue_loss' => round($bucket['total_revenue_loss'], 2),
                    'total_production_cost' => round($bucket['total_production_cost'], 2),
                    'global_production_cost' => round($bucket['global_production_cost'], 2),
                    'ratio' => $metrics['ratio'],
                    'ratio_percentage' => $metrics['ratio_percentage'],
                    'quantity_share_percentage' => $metrics['quantity_share_percentage'],
                    'sale_share_percentage' => $metrics['sale_share_percentage'],
                    'cost_share_percentage' => $metrics['cost_share_percentage'],
                ];
            })->values();

            $clientMetrics = $computeRatioAndShares(
                $qty,
                $times,
                $totalClientSalePrice,
                $totalClientProdCost,
                $globalTotalQty,
                $globalTotalSalePrice,
                $globalTotalProdCost,
                false
            );

            return [
                'free_client_uuid' => $entry['free_client_uuid'],
                'full_name' => $entry['full_name'],
                'total_times_ordered' => $times,
                'total_ordered_quantity' => $qty,
                'total_sale_price' => round($entry['total_sale_price'], 2),
                'total_revenue_loss' => round($entry['total_revenue_loss'], 2),
                'total_production_cost' => round($entry['total_production_cost'], 2),
                'global_production_cost' => round($entry['global_production_cost'], 2),
                'ratio' => $clientMetrics['ratio'],
                'ratio_percentage' => $clientMetrics['ratio_percentage'],
                'quantity_share_percentage' => $clientMetrics['quantity_share_percentage'],
                'sale_share_percentage' => $clientMetrics['sale_share_percentage'],
                'cost_share_percentage' => $clientMetrics['cost_share_percentage'],
                'rubriques' => $rubriques,
            ];
        })->sortByDesc('total_production_cost')->values();

        $selectedFreeClientCost = $freeClientUuidFilter
            ? ($freeClientsSummary->firstWhere('free_client_uuid', $freeClientUuidFilter) ?? [
                'free_client_uuid' => $freeClientUuidFilter,
                'full_name' => $resolveFullName(FreeClientRestaurant::withTrashed()->find($freeClientUuidFilter)),
                'total_times_ordered' => 0,
                'total_ordered_quantity' => 0,
                'total_sale_price' => 0,
                'total_revenue_loss' => 0,
                'total_production_cost' => 0,
                'global_production_cost' => 0,
                'ratio' => 0,
                'ratio_percentage' => 0,
                'quantity_share_percentage' => 0,
                'sale_share_percentage' => 0,
                'cost_share_percentage' => 0,
                'rubriques' => [],
            ])
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des offres récupérées avec succès.',
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'free_client_uuid' => $freeClientUuidFilter,
                'selected_free_client_cost' => $selectedFreeClientCost,
                'free_clients_summary' => $freeClientsSummary,
                'top_free_menus' => $topMenus,
                'top_free_complements' => $topComplements,
                'top_free_drinks' => $topDrinks,
                'top_free_others_drinks' => $topOthersMenu,
            ],
        ]);
    }
}

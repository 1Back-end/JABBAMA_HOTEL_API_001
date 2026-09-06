<?php

namespace App\Http\Controllers;

use App\Enums\ChooseClients;
use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\OrderMenuItemStatus;
use App\Models\OrderMenuRestaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataController extends Controller
{
    public function get_GLE_for_main_courante(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->get();
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
                ->with([
                    'salesCategory:uuid,name,code',
                    'items.menu:uuid,is_generated_from_complement'
                ])
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->get();

            $categoriesTotals = $orders->groupBy(function ($order) {
                return $order->salesCategory ? $order->salesCategory->name : 'AUTRES';
            })->map(function ($group) {
                return (float) $group->sum(function ($order) {
                    return $order->items->filter(function ($item) {
                        return $item->menu && !$item->menu->is_generated_from_complement;
                    })->sum('total_price');
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
                ->where('status', MenuOrderStatus::FACTURATE->value)
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
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->with(['items.menu'])
                ->get();
            $total = 0;

            foreach ($orders as $order) {
                $uniqueItems = $order->items->unique('uuid');
                foreach ($uniqueItems as $item) {
                    $isComplement = $item->menu ? (bool)$item->menu->is_generated_from_complement : false;
                    if ($item->menu && $isComplement === true) {
                        $itemTotal = (float) ($item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0)));
                        $total += $itemTotal;
                    }
                }
            }
            return response()->json([
                'success' => true,
                'date' => $date,
                'total_order' => $total
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul du total des commandes.",
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
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->with([
                    'salesCategory:uuid,name,code',
                    'items.menu:uuid,is_generated_from_complement'
                ])
                ->get();

            $categoriesCounts = [];

            foreach ($orders as $order) {
                $categoryName = $order->salesCategory ? $order->salesCategory->name : 'AUTRES';

                $validItems = $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                });

                $totalQuantityItems = $validItems->sum('quantity_exactly');

                if (!isset($categoriesCounts[$categoryName])) {
                    $categoriesCounts[$categoryName] = 0;
                }

                $categoriesCounts[$categoryName] += (int) $totalQuantityItems;
            }

            return response()->json([
                'success' => true,
                'date' => $date,
                'counts_by_category' => $categoriesCounts
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul des totaux par catégorie de vente.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_restaurant_bar_count(Request $request)
    {
        $auth = auth()->user();
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->with('drinks')
                ->get();

            $totalDrinksQuantity = (int) $orders->sum(function ($order) {
                return $order->drinks->sum('quantity_exactly');
            });

            return response()->json([
                'success' => true,
                'date' => $date,
                'count_bar' => $totalDrinksQuantity
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
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->with(['items.menu'])
                ->get();

            $totalQuantityDivers = 0;

            foreach ($orders as $order) {
                $validItems = $order->items->filter(function ($item) {
                    return $item->menu && $item->menu->is_generated_from_complement == true;
                });

                $totalQuantityDivers += (int) $validItems->sum('quantity_exactly');
            }

            return response()->json([
                'success' => true,
                'date' => $date,
                'count_order' => $totalQuantityDivers
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du comptage des divers.",
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getMainCouranteData(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->with([
                    'restaurantTable:uuid,code,table_number',
                    'restaurant_room:uuid,rooms_number',
                    'salesCategory:uuid,name,start_time,end_time',
                    'items.menu:uuid,code,name,have_complements,type_complement_menu,have_complements,have_drinks,is_generated_from_complement',
                    'items.virtuals.product:uuid,name,code',
                    'items.complements.complement',
                    'payment.regulations.method',
                    'drinks.drinkConfig.product',
                ])
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->get();

            $countsByCategory = $orders->groupBy(function ($order) {
                return $order->salesCategory ? strtoupper($order->salesCategory->name) : 'AUTRES';
            })->map(function ($group) {
                return (int) $group->sum(function ($order) {
                    return $order->items->where('menu.is_generated_from_complement', false)->sum('quantity_exactly');
                });
            });

            $totalBar = (int) $orders->sum(function ($order) {
                return $order->drinks->sum('quantity_exactly');
            });

            $totalRoomService = (int) $orders->sum('total_order');

            $formattedOrders = [];
            $debiteursOrders = [];

            foreach ($orders as $order) {
                $categoryName = $order->salesCategory ? strtoupper($order->salesCategory->name) : 'AUTRES';
                $roomServicePrice = (int) ($order->price_for_room_service ?? 0) * (int) ($order->quantity_for_room_service ?? 0) ;
                $roomServiceQuantity = (int) ($order->quantity_for_room_service ?? 0);
                $roomServiceUnitPrice = (int) ($order->price_for_room_service ?? 0);

                $formattedItems = $order->items
                    ->filter(function ($item) {
                        return $item->menu && !$item->menu->is_generated_from_complement;
                    })
                    ->map(function ($item) {
                        return [
                            'menu' => $item->menu ? $item->menu->name : null,
                            'quantity' => $item->quantity_exactly,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    });

                $formattedDrinks = $order->drinks->map(function ($drink) {
                    return [
                        'menu' => $drink->drinkConfig && $drink->drinkConfig->product ? $drink->drinkConfig->product->name : 'Boisson',
                        'quantity' => $drink->quantity_exactly,
                        'unit_price' => $drink->unit_price,
                        'total_price' => $drink->total_price,
                    ];
                });

                $paymentMethods = [];
                if ($order->payment && $order->payment->regulations) {
                    $paymentMethods = $order->payment->regulations->map(function ($regulation) {
                        return [
                            'amount' => $regulation->amount ?? 0,
                            'method_name' => $regulation->method->name ?? 'Inconnu',
                        ];
                    });
                }

                $totalAmount = $order->total_order ?? 0;

                $orderData = [
                    'uuid' => $order->uuid,
                    'code_facture' => $order->code,
                    'no_table' => $order->restaurantTable->table_number ?? '',
                    'chambre' => $order->restaurant_room->rooms_number ?? '',
                    'payment_mode' => $order->status_payment_label ?? '',
                    'regulation_status' => $order->status_payment_label,
                    'payment_status' => $order->regulation_status,
                    'total_amount' => $totalAmount,
                    'price_for_room_service' => $roomServicePrice,
                    'quantity_for_room_service' => $roomServiceQuantity,
                    'unit_price_for_room_service' => $roomServiceUnitPrice,
                    'payment_methods' => $paymentMethods,
                    'sales_category' => $categoryName,
                    'items' => $formattedItems->values()->all(),
                    'drinks' => $formattedDrinks->values()->all()
                ];

                $formattedOrders[] = $orderData;

                $debiteurItems = $order->items->filter(function ($item) {
                    return $item->menu && $item->menu->is_generated_from_complement == true;
                });

                if ($debiteurItems->isNotEmpty()) {
                    $formattedDebiteurItems = $debiteurItems->map(function ($item) {
                        return [
                            'menu' => $item->menu ? $item->menu->name : null,
                            'quantity' => $item->quantity_exactly,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    });

                    $debiteursOrders[] = [
                        'uuid' => $order->uuid,
                        'code_facture' => $order->code,
                        'no_table' => $order->restaurantTable->table_number ?? '',
                        'chambre' => $order->restaurant_room->rooms_number ?? '',
                        'sales_category' => $categoryName,
                        'price_for_room_service' => $roomServicePrice,
                        'quantity_for_room_service' => $roomServiceQuantity,
                        'items' => $formattedDebiteurItems->values()->all(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'date' => $date,
                'summary_counts' => [
                    'total_room_service' => $totalRoomService,
                    'bar' => $totalBar,
                    'by_category' => $countsByCategory
                ],
                'orders' => $formattedOrders,
                'debiteurs' => $debiteursOrders
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération des données de la main courante.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_total_room_service_quantity(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $totalQuantityRoomService = (int) OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->where('is_room_service', true)
                ->sum('quantity_for_room_service');

            return response()->json([
                'success' => true,
                'date' => $date,
                'total_room_service_quantity' => $totalQuantityRoomService
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul de la quantité totale des room services.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_total_room_service_amount(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $totalAmountRoomService = (int) OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->where('is_room_service', true)
                ->sum(DB::raw('price_for_room_service * quantity_for_room_service'));

            return response()->json([
                'success' => true,
                'date' => $date,
                'total_room_service_amount' => $totalAmountRoomService
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du calcul du montant total des room services.",
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function get_all_order_not_traited(Request $request)
    {
        $count = OrderMenuRestaurant::where('status', '!=', MenuOrderStatus::FACTURATE->value)
            ->count();

        return response()->json([
            'success' => true,
            'count' => $count
        ]);
    }

    public function exportMainCourantePdf(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->with([
                    'restaurantTable:uuid,code,table_number',
                    'restaurant_room:uuid,rooms_number',
                    'salesCategory:uuid,name,start_time,end_time',
                    'items.menu:uuid,code,name,have_complements,type_complement_menu,is_generated_from_complement',
                    'items.virtuals.product:uuid,name,code',
                    'items.complements.complement',
                    'payment.regulations.method',
                    'drinks.drinkConfig.product',
                ])
                ->where('status', MenuOrderStatus::FACTURATE->value)
                ->get();


            $ordersNotTraited = OrderMenuRestaurant::where('status', '!=', MenuOrderStatus::FACTURATE->value)
                ->with([
                    'restaurantTable:uuid,code,table_number',
                    'restaurant_room:uuid,rooms_number',
                    'salesCategory:uuid,name',
                ])
                ->get();

            $totalGle = (int) $orders->sum('total_order');

            $ordersEncaissement = $orders->filter(function ($order) {
                return in_array($order->regulation_status, [
                    PaymentOrderMenusStatus::PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ]);
            });
            $totalEncaissement = (int) $ordersEncaissement->sum('computed_paid_amount');

            $ordersNotPaid = $orders->filter(function ($order) {
                return in_array($order->regulation_status, [
                    PaymentOrderMenusStatus::NOT_PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ]);
            });
            $totalNotPaid = (int) $ordersNotPaid->sum('total_order');

            $countsByCategory = [];
            $amountsByCategory = [];

            foreach ($orders as $order) {
                $categoryName = $order->salesCategory ? strtoupper(trim($order->salesCategory->name)) : 'AUTRES';

                $validItems = $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                });

                $countsByCategory[$categoryName] = ($countsByCategory[$categoryName] ?? 0) + (int) $validItems->sum('quantity_exactly');
                $amountsByCategory[$categoryName] = ($amountsByCategory[$categoryName] ?? 0) + (float) $validItems->sum('total_price');
            }

            $totalBarAmount = (float) $orders->sum('total_drinks');
            $totalBarCount = (int) $orders->sum(function ($order) {
                return $order->drinks->sum('quantity_exactly');
            });

            $totalDiversAmount = 0;
            $totalDiversCount = 0;
            foreach ($orders as $order) {
                $diversItemsFilter = $order->items->filter(function ($item) {
                    return $item->menu && (bool)$item->menu->is_generated_from_complement === true;
                });
                $totalDiversAmount += (float) $diversItemsFilter->sum(function ($item) {
                    return $item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));
                });
                $totalDiversCount += (int) $diversItemsFilter->sum('quantity_exactly');
            }

            $totalRoomServiceAmount = (int) $orders->sum(function ($order) {
                $rsPrice = (int) ($order->price_for_room_service ?? 0);
                $rsQty = (int) ($order->quantity_for_room_service ?? 1);
                return $rsPrice * $rsQty;
            });

            $totalRoomServiceQuantity = (int) $orders->sum('quantity_for_room_service');

            $formattedOrders = [];
            $debiteursOrders = [];

            foreach ($orders as $order) {
                $categoryName = $order->salesCategory ? strtoupper(trim($order->salesCategory->name)) : 'AUTRES';
                $roomServicePriceCalculated = (int) ($order->price_for_room_service ?? 0) * (int) ($order->quantity_for_room_service ?? 1);

                $formattedItems = $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                })->map(function ($item) {
                    return [
                        'menu' => $item->menu->name ?? null,
                        'quantity' => $item->quantity_exactly,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                    ];
                });

                $diversItems = $order->items->filter(function ($item) {
                    return $item->menu && (bool)$item->menu->is_generated_from_complement === true;
                })->map(function ($item) {
                    return [
                        'menu' => $item->menu->name ?? null,
                        'quantity' => $item->quantity_exactly,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                    ];
                });

                $formattedDrinks = $order->drinks->map(function ($drink) {
                    return [
                        'menu' => $drink->drinkConfig && $drink->drinkConfig->product ? $drink->drinkConfig->product->name : 'Boisson',
                        'quantity' => $drink->quantity_exactly,
                        'unit_price' => $drink->unit_price,
                        'total_price' => $drink->total_price,
                    ];
                });

                $paymentMethods = [];
                if ($order->payment && $order->payment->regulations) {
                    $paymentMethods = $order->payment->regulations->map(function ($regulation) {
                        return [
                            'amount' => $regulation->amount ?? 0,
                            'method_name' => $regulation->method->name ?? 'Inconnu',
                        ];
                    });
                }

                if ($diversItems->isNotEmpty()) {
                    $debiteursOrders[] = [
                        'uuid' => $order->uuid,
                        'code_facture' => $order->code,
                        'no_table' => $order->restaurantTable->table_number ?? '',
                        'chambre' => $order->restaurant_room->rooms_number ?? '',
                        'sales_category' => $categoryName,
                        'price_for_room_service' => $roomServicePriceCalculated,
                        'quantity_for_room_service' => (int) ($order->quantity_for_room_service ?? 0),
                        'items' => $diversItems->values()->all(),
                        'drinks' => [],
                    ];
                }

                $formattedOrders[] = [
                    'uuid' => $order->uuid,
                    'code_facture' => $order->code,
                    'no_table' => $order->restaurantTable->table_number ?? '',
                    'chambre' => $order->restaurant_room->rooms_number ?? '',
                    'payment_mode' => $order->status_payment_label ?? '',
                    'regulation_status' => $order->status_payment_label,
                    'payment_status' => $order->regulation_status,
                    'total_amount' => $order->total_order ?? 0,
                    'price_for_room_service' => $roomServicePriceCalculated,
                    'quantity_for_room_service' => (int) ($order->quantity_for_room_service ?? 0),
                    'payment_methods' => $paymentMethods,
                    'sales_category' => $categoryName,
                    'items' => $formattedItems->values()->all(),
                    'drinks' => $formattedDrinks->values()->all(),
                    'divers' => $diversItems->values()->all(),
                ];
            }

            // Formatage des commandes non traitées pour la vue
            $formattedOrdersNotTraited = $ordersNotTraited->map(function ($order) {
                return [
                    'code_facture' => $order->code,
                    'no_table' => $order->restaurantTable->table_number ?? '',
                    'chambre' => $order->restaurant_room->rooms_number ?? '',
                    'sales_category' => $order->salesCategory ? strtoupper(trim($order->salesCategory->name)) : 'AUTRES',
                    'total_amount' => $order->total_order ?? 0,
                    'status' => $order->status,
                ];
            })->values()->all();

            $fileName   = 'MAIN-COURANTE-' . $date . '.pdf';
            $folderPath = 'storage/main-courante/' . now()->format('d-m-Y') . '/';
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'date' => $date,
                'total_gle' => $totalGle,
                'total_encaissement' => $totalEncaissement,
                'total_debiteur' => $totalNotPaid,
                'counts_by_category' => $countsByCategory,
                'amounts_by_category' => $amountsByCategory,
                'total_bar_amount' => $totalBarAmount,
                'total_bar_count' => $totalBarCount,
                'total_divers_amount' => $totalDiversAmount,
                'total_divers_count' => $totalDiversCount,
                'total_room_service_amount' => $totalRoomServiceAmount,
                'total_room_service_quantity' => $totalRoomServiceQuantity,
                'orders' => $formattedOrders,
                'orders_not_traited' => $formattedOrdersNotTraited,
                'debiteurs' => $debiteursOrders,
            ];

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.main-courante.main-courante',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                format: 'A3',
                direction: 'landscape',
                footer: $footer,
                margins: [5, 5, 5, 5]
            );

            if (!file_exists($filePath)) {
                return response()->json(['message' => "Le fichier PDF n'a pas été généré."], 500);
            }

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'success' => true,
                'data' => $data,
                'base64' => $base64,
                'url' => asset('storage/details-orders/' . $fileName),
                'filename' => $fileName,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la génération du PDF de la main courante.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

}


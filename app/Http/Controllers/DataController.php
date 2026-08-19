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
                ->with([
                    'salesCategory:uuid,name,code',
                    'items.menu:uuid,is_generated_from_complement'
                ])
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

                // 1. Filtrer les items principaux (is_generated_from_complement == false)
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

                // Calcul du montant total de la facture (Items + Drinks ou via total_order)
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
                    'payment_methods' => $paymentMethods,
                    'sales_category' => $categoryName,
                    'items' => $formattedItems->values()->all(),
                    'drinks' => $formattedDrinks
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
}

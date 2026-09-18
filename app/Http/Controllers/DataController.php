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

    public function getCompleteMainCouranteData(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : now()->toDateString();

        try {
            $orders = OrderMenuRestaurant::whereDate('created_at', $date)
                ->with([
                    'restaurantTable:uuid,code,table_number',
                    'restaurant_room:uuid,rooms_number',
                    'salesCategory:uuid,name,code,start_time,end_time',
                    'items.menu:uuid,code,name,have_complements,type_complement_menu,is_generated_from_complement',
                    'items.virtuals.product:uuid,name,code',
                    'items.complements.complement',
                    'payment.regulations.method',
                    'drinks.drinkConfig.product',
                ])
                ->get();


            $facturedOrders = $orders->where('status', MenuOrderStatus::FACTURATE->value);

            $totalGle = (int) $facturedOrders->sum('total_order');

            $totalEncaissement = (int) $orders->filter(function ($order) {
                return in_array($order->regulation_status, [
                    PaymentOrderMenusStatus::PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ]);
            })->sum('computed_paid_amount');

            $totalNotPaid = (int) $orders->filter(function ($order) {
                return in_array($order->regulation_status, [
                    PaymentOrderMenusStatus::NOT_PAID->value,
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                ]);
            })->sum('total_order');

            $countNotTraited = $orders->where('status', '!=', MenuOrderStatus::FACTURATE->value)->count();

            $roomServiceOrders = $facturedOrders->where('is_room_service', true);
            $totalRoomServiceQuantity = (int) $roomServiceOrders->sum('quantity_for_room_service');
            $totalRoomServiceAmount = (int) $roomServiceOrders->sum(function ($order) {
                return (int)($order->price_for_room_service ?? 0) * (int)($order->quantity_for_room_service ?? 0);
            });

            $totalBar = (float) $facturedOrders->sum('total_drinks');
            $countBar = (int) $facturedOrders->sum(function ($order) {
                return $order->drinks->sum('quantity_exactly');
            });

            $categoriesTotals = [];
            $categoriesCounts = [];

            foreach ($facturedOrders as $order) {
                $catName = $order->salesCategory ? strtoupper($order->salesCategory->name) : 'AUTRES';

                $validItems = $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                });

                if (!isset($categoriesTotals[$catName])) {
                    $categoriesTotals[$catName] = 0.0;
                    $categoriesCounts[$catName] = 0;
                }

                $categoriesTotals[$catName] += (float) $validItems->sum('total_price');
                $categoriesCounts[$catName] += (int) $validItems->sum('quantity_exactly');
            }

            $totalDiversOrder = 0;
            $totalQuantityDivers = 0;

            foreach ($facturedOrders as $order) {
                $diversItems = $order->items->filter(function ($item) {
                    return $item->menu && (bool)$item->menu->is_generated_from_complement === true;
                });

                foreach ($diversItems as $item) {
                    $totalDiversOrder += (float) ($item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0)));
                }
                $totalQuantityDivers += (int) $diversItems->sum('quantity_exactly');
            }

            $formattedOrders = [];
            $debiteursOrders = [];

            foreach ($facturedOrders as $order) {
                $categoryName = $order->salesCategory ? strtoupper($order->salesCategory->name) : 'AUTRES';
                $roomServicePrice = (int) ($order->price_for_room_service ?? 0) * (int) ($order->quantity_for_room_service ?? 0);
                $roomServiceQuantity = (int) ($order->quantity_for_room_service ?? 0);
                $roomServiceUnitPrice = (int) ($order->price_for_room_service ?? 0);

                $formattedItems = $order->items
                    ->filter(fn($item) => $item->menu && !$item->menu->is_generated_from_complement)
                    ->map(fn($item) => [
                        'menu' => $item->menu?->name,
                        'quantity' => $item->quantity_exactly,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                    ]);

                $formattedDrinks = $order->drinks->map(fn($drink) => [
                    'menu' => $drink->drinkConfig?->product?->name ?? 'Boisson',
                    'quantity' => $drink->quantity_exactly,
                    'unit_price' => $drink->unit_price,
                    'total_price' => $drink->total_price,
                ]);

                $paymentMethods = [];
                if ($order->payment && $order->payment->regulations) {
                    $paymentMethods = $order->payment->regulations
                        ->groupBy(fn($reg) => $reg->method->name ?? 'Inconnu')
                        ->map(fn($group, $methodName) => [
                            'method_name' => $methodName,
                            'amount' => $group->sum('amount'),
                        ])
                        ->values()
                        ->all();
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
                    'price_for_room_service' => $roomServicePrice,
                    'quantity_for_room_service' => $roomServiceQuantity,
                    'unit_price_for_room_service' => $roomServiceUnitPrice,
                    'payment_methods' => $paymentMethods,
                    'sales_category' => $categoryName,
                    'type_clients_for_payment' => TypeClientsForPaiment::safeLabel($order->type_clients_for_payment),
                    'items' => $formattedItems->values()->all(),
                    'drinks' => $formattedDrinks->values()->all()
                ];

                $debiteurItems = $order->items->filter(fn($item) => $item->menu && (bool)$item->menu->is_generated_from_complement === true);
                if ($debiteurItems->isNotEmpty()) {
                    $formattedDebiteurItems = $debiteurItems->map(fn($item) => [
                        'menu' => $item->menu?->name,
                        'quantity' => $item->quantity_exactly,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                    ]);

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
                'total_gle' => $totalGle,
                'total_encaissement' => $totalEncaissement,
                'total_not_paid' => $totalNotPaid,
                'total_bar' => $totalBar,
                'count_bar' => $countBar,
                'total_order' => $totalDiversOrder,
                'count_order' => $totalQuantityDivers,
                'total_room_service_quantity' => $totalRoomServiceQuantity,
                'total_room_service_amount' => $totalRoomServiceAmount,
                'count_not_traited' => $countNotTraited,
                'totals_by_category' => $categoriesTotals,
                'counts_by_category' => $categoriesCounts,
                'summary_counts' => [
                    'total_room_service' => $totalRoomServiceAmount,
                    'bar' => $totalBar,
                    'by_category' => $categoriesCounts
                ],
                'orders' => $formattedOrders,
                'debiteurs' => $debiteursOrders
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération globale de la main courante.",
                'error' => $e->getMessage()
            ], 500);
        }
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


            $ordersNotTraited = OrderMenuRestaurant::whereDate('created_at', $date)
                ->where('status', '!=', MenuOrderStatus::FACTURATE->value)
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
                    $paymentMethods = $order->payment->regulations
                        ->groupBy(function ($regulation) {
                            return $regulation->method->name ?? 'Inconnu';
                        })
                        ->map(function ($group, $methodName) {
                            return [
                                'method_name' => $methodName,
                                'amount' => $group->sum('amount'),
                            ];
                        })
                        ->values()
                        ->all();
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
                $clientTypeVal = $order->type_clients_for_payment ?? null;

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
                    'type_clients_for_payment' => TypeClientsForPaiment::safeLabel($clientTypeVal),
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


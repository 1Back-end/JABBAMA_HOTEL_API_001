<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Models\OrderMenuRestaurant;
use App\Models\PaymentRegulation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OperationalMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $auth = auth()->user();

        $hasExplicitDate = $request->has('date') || $request->has('date_debut');
        $dateInput = $request->input('date', now()->toDateString());

        if (str_contains($dateInput, ' to ')) {
            $dates = explode(' to ', $dateInput);
            $dateP1 = Carbon::parse(trim($dates[1] ?? $dates[0]))->toDateString();
        } elseif (str_contains($dateInput, ' - ')) {
            $dates = explode(' - ', $dateInput);
            $dateP1 = Carbon::parse(trim($dates[1] ?? $dates[0]))->toDateString();
        } else {
            $dateP1 = Carbon::parse($dateInput)->toDateString();
        }

        if (!$hasExplicitDate) {
            $dateP1 = Carbon::parse($dateP1)->subDay()->toDateString();
        }

        $dateDebutP1 = $dateP1;
        $dateFinP1   = $dateP1;

        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $dateDebutP2 = Carbon::parse($request->input('date_debut'))->toDateString();
            $dateFinP2   = Carbon::parse($request->input('date_fin'))->toDateString();
        } elseif ($request->filled('p2_date_debut') && $request->filled('p2_date_fin')) {
            $dateDebutP2 = Carbon::parse($request->input('p2_date_debut'))->toDateString();
            $dateFinP2   = Carbon::parse($request->input('p2_date_fin'))->toDateString();
        } else {
            $dateReference = Carbon::parse($dateFinP1);
            $dateDebutP2 = $dateReference->copy()->startOfMonth()->toDateString();
            $dateFinP2   = $dateReference->copy()->endOfMonth()->toDateString();
        }

        try {
            $getUnpaidOrdersForPeriod = function ($startDate, $endDate) {
                $query = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                    ->whereIn('regulation_status', [
                        PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                        PaymentOrderMenusStatus::NOT_PAID->value,
                    ])
                    ->with('payment');

                if ($startDate === $endDate) {
                    $query->whereDate('created_at', $startDate);
                } else {
                    $query->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }

                return $query->get();
            };

            $globalOrdersP1 = $getUnpaidOrdersForPeriod($dateDebutP1, $dateFinP1);
            $report_amount_p1 = 0;
            foreach ($globalOrdersP1 as $order) {
                $amount = ($order->regulation_status === PaymentOrderMenusStatus::PARTIALLY_PAID->value)
                    ? (float) ($order->remaining_amount ?? 0)
                    : (float) ($order->total_order ?? 0);
                $report_amount_p1 += $amount;
            }

            $globalOrdersP2 = $getUnpaidOrdersForPeriod($dateDebutP2, $dateFinP2);
            $report_amount_p2 = 0;
            foreach ($globalOrdersP2 as $order) {
                $amount = ($order->regulation_status === PaymentOrderMenusStatus::PARTIALLY_PAID->value)
                    ? (float) ($order->remaining_amount ?? 0)
                    : (float) ($order->total_order ?? 0);
                $report_amount_p2 += $amount;
            }

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

                $totalAmountRoomService = (float) $orders->where('is_room_service', true)->sum(function ($order) {
                    $price = (float) str_replace(',', '.', $order->price_for_room_service ?? 0);
                    $quantity = (int) ($order->quantity_for_room_service ?? 0);
                    return $price * $quantity;
                });
                $totalQuantityRoomService = (int) $orders->where('is_room_service', true)->sum('quantity_for_room_service');

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


                $expensesQuery = PaymentRegulation::where('type', 'expense');

                if ($startDate === $endDate) {
                    $expensesQuery->whereDate('created_at', $startDate);
                } else {
                    $expensesQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }
                $totalExpenses = (float) $expensesQuery->sum('amount');

                $totalProduits = (float) $categoriesTotals->sum()
                    + (float) $totalBar
                    + (float) $totalAmountRoomService
                    + (float) $totalAmountDivers;

                return [
                    'total_produits' => $totalProduits,
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
                    'total_expenses' => $totalExpenses,
                ];
            };

            $dataP1 = $calculateMetrics($dateDebutP1, $dateFinP1);
            $dataP2 = $calculateMetrics($dateDebutP2, $dateFinP2);

            return response()->json([
                'success' => true,
                'periode_1' => ['date_debut' => $dateDebutP1, 'date_fin' => $dateFinP1],
                'periode_2' => ['date_debut' => $dateDebutP2, 'date_fin' => $dateFinP2],


                'total_produits_p1' => $dataP1['total_produits'],
                'total_produits_p2' => $dataP2['total_produits'],

                'expenses_1' => $dataP1['total_expenses'],
                'expenses_2' => $dataP2['total_expenses'],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données : ' . $e->getMessage()
            ], 500);
        }
    }

    public function print_situations_sheet(Request $request)
    {
        $auth = auth()->user();

        $hasExplicitDate = $request->has('date') || $request->has('date_debut');
        $dateInput = $request->input('date', now()->toDateString());

        if (str_contains($dateInput, ' to ')) {
            $dates = explode(' to ', $dateInput);
            $dateP1 = Carbon::parse(trim($dates[1] ?? $dates[0]))->toDateString();
        } elseif (str_contains($dateInput, ' - ')) {
            $dates = explode(' - ', $dateInput);
            $dateP1 = Carbon::parse(trim($dates[1] ?? $dates[0]))->toDateString();
        } else {
            $dateP1 = Carbon::parse($dateInput)->toDateString();
        }

        if (!$hasExplicitDate) {
            $dateP1 = Carbon::parse($dateP1)->subDay()->toDateString();
        }

        $dateDebutP1 = $dateP1;
        $dateFinP1   = $dateP1;

        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $dateDebutP2 = Carbon::parse($request->input('date_debut'))->toDateString();
            $dateFinP2   = Carbon::parse($request->input('date_fin'))->toDateString();
        } elseif ($request->filled('p2_date_debut') && $request->filled('p2_date_fin')) {
            $dateDebutP2 = Carbon::parse($request->input('p2_date_debut'))->toDateString();
            $dateFinP2   = Carbon::parse($request->input('p2_date_fin'))->toDateString();
        } else {
            $dateReference = Carbon::parse($dateFinP1);
            $dateDebutP2 = $dateReference->copy()->startOfMonth()->toDateString();
            $dateFinP2   = $dateReference->copy()->endOfMonth()->toDateString();
        }

        try {
            $getUnpaidOrdersForPeriod = function ($startDate, $endDate) {
                $query = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                    ->whereIn('regulation_status', [
                        PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                        PaymentOrderMenusStatus::NOT_PAID->value,
                    ])
                    ->with('payment');

                if ($startDate === $endDate) {
                    $query->whereDate('created_at', $startDate);
                } else {
                    $query->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }

                return $query->get();
            };

            $globalOrdersP1 = $getUnpaidOrdersForPeriod($dateDebutP1, $dateFinP1);
            $report_amount_p1 = 0;
            foreach ($globalOrdersP1 as $order) {
                $amount = ($order->regulation_status === PaymentOrderMenusStatus::PARTIALLY_PAID->value)
                    ? (float) ($order->remaining_amount ?? 0)
                    : (float) ($order->total_order ?? 0);
                $report_amount_p1 += $amount;
            }

            $globalOrdersP2 = $getUnpaidOrdersForPeriod($dateDebutP2, $dateFinP2);
            $report_amount_p2 = 0;
            foreach ($globalOrdersP2 as $order) {
                $amount = ($order->regulation_status === PaymentOrderMenusStatus::PARTIALLY_PAID->value)
                    ? (float) ($order->remaining_amount ?? 0)
                    : (float) ($order->total_order ?? 0);
                $report_amount_p2 += $amount;
            }

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

                $totalAmountRoomService = (float) $orders->where('is_room_service', true)->sum(function ($order) {
                    $price = (float) str_replace(',', '.', $order->price_for_room_service ?? 0);
                    $quantity = (int) ($order->quantity_for_room_service ?? 0);
                    return $price * $quantity;
                });
                $totalQuantityRoomService = (int) $orders->where('is_room_service', true)->sum('quantity_for_room_service');

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

                $expensesQuery = PaymentRegulation::where('type', 'expense');

                if ($startDate === $endDate) {
                    $expensesQuery->whereDate('created_at', $startDate);
                } else {
                    $expensesQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }
                $totalExpenses = (float) $expensesQuery->sum('amount');

                $totalProduits = (float) $categoriesTotals->sum()
                    + (float) $totalBar
                    + (float) $totalAmountRoomService
                    + (float) $totalAmountDivers;

                return [
                    'total_produits' => $totalProduits,
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
                    'total_expenses' => $totalExpenses,
                ];
            };

            $dataP1 = $calculateMetrics($dateDebutP1, $dateFinP1);
            $dataP2 = $calculateMetrics($dateDebutP2, $dateFinP2);

            $totalProduitsP1 = $dataP1['total_produits'];
            $expensesP1 = $dataP1['total_expenses'];
            $expenseRateP1 = $totalProduitsP1 > 0 ? ($expensesP1 / $totalProduitsP1) * 100 : 0;
            $marginP1 = $totalProduitsP1 - $expensesP1;
            $marginRateP1 = 100 - $expenseRateP1;

            $totalProduitsP2 = $dataP2['total_produits'];
            $expensesP2 = $dataP2['total_expenses'];
            $expenseRateP2 = $totalProduitsP2 > 0 ? ($expensesP2 / $totalProduitsP2) * 100 : 0;
            $marginP2 = $totalProduitsP2 - $expensesP2;
            $marginRateP2 = 100 - $expenseRateP2;

            $dateFinP1Formatted = mb_strtoupper(Carbon::parse($dateFinP1)->locale('fr')->isoFormat('D MMMM YYYY'));
            $dateDebutP2Formatted = mb_strtoupper(Carbon::parse($dateDebutP2)->locale('fr')->isoFormat('D MMMM YYYY'));
            $dateFinP2Formatted = mb_strtoupper(Carbon::parse($dateFinP2)->locale('fr')->isoFormat('D MMMM YYYY'));

            $dynamicTitle = "SUIVI D'EXPLOITATION DU " . $dateFinP1Formatted . " - INTERVALLE DU " . $dateDebutP2Formatted . " AU " . $dateFinP2Formatted;

            $data = [
                'success' => true,
                'title' => $dynamicTitle,
                'date' => $dateDebutP1,
                'start_date' => $dateDebutP2,
                'end_date' => $dateFinP2,
                'periode_1' => ['date_debut' => $dateDebutP1, 'date_fin' => $dateFinP1],
                'periode_2' => ['date_debut' => $dateDebutP2, 'date_fin' => $dateFinP2],
                'total_produits_p1' => $totalProduitsP1,
                'total_produits_p2' => $totalProduitsP2,
                'expenses_1' => $expensesP1,
                'expenses_2' => $expensesP2,
                'expense_rate_p1' => $expenseRateP1,
                'expense_rate_p2' => $expenseRateP2,
                'margin_p1' => $marginP1,
                'margin_p2' => $marginP2,
                'margin_rate_p1' => $marginRateP1,
                'margin_rate_p2' => $marginRateP2,
            ];

            $fileName   = 'SUIVIE-DEXPLOITATION-' . $dateDebutP1 . '.pdf';
            $folderPath = 'storage/operational_monitoring/' . now()->format('d-m-Y') . '/';
            $filePath   = $folderPath . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.operational_monitoring.operational_monitoring',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                format: 'A5',
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
                'success'  => true,
                'data'     => $data,
                'base64'   => $base64,
                'url'      => asset('storage/operational_monitoring/' . now()->format('d-m-Y') . '/' . $fileName),
                'filename' => $fileName,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du PDF : ' . $e->getMessage()
            ], 500);
        }
    }
}

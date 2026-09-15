<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseSlug;
use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\RestaurantExpenseSlug;
use App\Models\ExpensePayment;
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


                $expensesQuery = PaymentRegulation::where('type', 'expense')
                    ->whereNotNull('slug')
                    ->whereIn(\DB::raw('UPPER(slug)'), ExpenseSlug::values());

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

                $expensesQuery = PaymentRegulation::where('type', 'expense')
                    ->whereNotNull('slug')
                    ->whereIn(\DB::raw('UPPER(slug)'), ExpenseSlug::values());

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

    private function buildExpenseTree($items)
    {
        $tree = [];

        foreach ($items as $item) {

            $current = &$tree;

            if ($item->hierarchy_families->isEmpty() && !$item->family) {
                $typeName = optional($item->expenseType)->name ?? 'Dépenses directes';
                $typeUuid = optional($item->expenseType)->uuid ?? null;
                $defaultKey = 'direct_type_' . ($typeUuid ?? 'general');

                if (!isset($current[$defaultKey])) {
                    $current[$defaultKey] = [
                        'uuid'     => $typeUuid,
                        'name'     => $typeName,
                        'amount'   => 0,
                        'children' => [],
                    ];
                }

                $current[$defaultKey]['amount'] += (float) $item->amount;

                continue;
            }

            $hierarchy = $item->hierarchy_families
                ->sortBy('level')
                ->values();

            foreach ($hierarchy as $family) {

                $uuid = $family->uuid;

                if (!isset($current[$uuid])) {

                    $current[$uuid] = [
                        'uuid'     => $uuid,
                        'name'     => $family->name,
                        'amount'   => 0,
                        'children' => [],
                    ];
                }

                $current[$uuid]['amount'] += (float) $item->amount;

                $current = &$current[$uuid]['children'];
            }

            if ($item->family) {

                $family = $item->family;

                if (!isset($current[$family->uuid])) {

                    $current[$family->uuid] = [
                        'uuid'     => $family->uuid,
                        'name'     => $family->name,
                        'amount'   => 0,
                        'children' => [],
                    ];
                }

                $current[$family->uuid]['amount'] += (float) $item->amount;

            } else {
                $current_key = 'direct_' . $item->uuid;
                $current[$current_key] = [
                    'uuid'     => $item->uuid,
                    'name'     => $item->name,
                    'amount'   => (float) $item->amount,
                    'children' => [],
                ];
            }

            unset($current);
        }

        return $this->normalizeTree($tree);
    }

    private function normalizeTree(array $tree): array
    {
        return collect($tree)
            ->map(function ($node) {

                $node['children'] = $this->normalizeTree($node['children']);

                return $node;

            })
            ->values()
            ->toArray();
    }


    public function get_detais_for_expenses(Request $request)
    {
        $auth = auth()->user();

        $rawDate = $request->input('date') ?? $request->input('date_debut') ?? now()->toDateString();

        try {
            $dateP1 = Carbon::parse($rawDate)->format('Y-m-d');
        } catch (\Exception $e) {
            $dateP1 = now()->toDateString();
        }

        $dateDebutP1 = $dateP1;
        $dateFinP1   = $dateP1;

        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $dateDebutP2 = Carbon::parse($request->input('date_debut'))->format('Y-m-d');
            $dateFinP2   = Carbon::parse($request->input('date_fin'))->format('Y-m-d');
        } elseif ($request->filled('p2_date_debut') && $request->filled('p2_date_fin')) {
            $dateDebutP2 = Carbon::parse($request->input('p2_date_debut'))->format('Y-m-d');
            $dateFinP2   = Carbon::parse($request->input('p2_date_fin'))->format('Y-m-d');
        } else {
            $dateReference = Carbon::parse($dateFinP1);
            $dateDebutP2 = $dateReference->copy()->startOfMonth()->format('Y-m-d');
            $dateFinP2   = $dateReference->copy()->endOfMonth()->format('Y-m-d');
        }

        $createdBy  = $request->filled('created_by') ? $request->created_by : null;
        $filterType = $request->input('filter_type', null);

        $allowedSlugs = RestaurantExpenseSlug::values();

        $dailyExpenses = collect();
        $expenses      = collect();

        $shouldFetchExpenses = $filterType !== 'payment_type' || $request->filled('restaurant_expense_type_uuid');

        if ($shouldFetchExpenses) {
            $dailyExpenses = $this->fetchExpensesByDateRange(
                Carbon::parse($dateDebutP1)->startOfDay(),
                Carbon::parse($dateFinP1)->endOfDay(),
                $createdBy,
                $allowedSlugs
            );


            $expenses = $this->fetchExpensesByDateRange(
                Carbon::parse($dateDebutP2)->startOfDay(),
                Carbon::parse($dateFinP2)->endOfDay(),
                $createdBy,
                $allowedSlugs
            );
        }

        return response()->json([
            'success'        => true,
            'date_p1'        => $dateDebutP1,
            'period_p2'      => [$dateDebutP2, $dateFinP2],
            'daily_expenses' => $dailyExpenses,
            'expenses'       => $expenses,
        ], 200);
    }

    /**
     * Fonction helper privée pour exécuter la requête d'expenses
     */
    private function fetchExpensesByDateRange($startDate, $endDate, $createdBy, array $allowedSlugs)
    {
        $query = ExpensePayment::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'expenseType:uuid,name,slug',
            'family:uuid,name',
            'method:uuid,name',
        ])
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->whereNotNull('slug')
            ->whereIn(\DB::raw('UPPER(slug)'), $allowedSlugs);

        if ($createdBy) {
            $query->where('created_by', $createdBy);
        }

        return $query->orderByDesc('paid_at')
            ->get()
            ->groupBy(function ($item) {
                return strtoupper($item->slug ?? '');
            })
            ->map(function ($items, $slug) {
                $firstItem = $items->first();
                $tree = $this->buildExpenseTree($items);
                if (count($tree) === 1 && strtoupper($tree[0]['name']) === strtoupper('DEPENSES ' . $slug)) {
                    $families = $tree[0]['children'];
                } else {
                    $families = $tree;
                }

                return [
                    'expense_type' => $firstItem->expenseType,
                    'title'        => 'DEPENSES ' . $slug,
                    'total_amount' => (float) $items->sum('amount'),
                    'families'     => $families,
                    'isLoading'    => false,
                ];
            })
            ->values();
    }








}

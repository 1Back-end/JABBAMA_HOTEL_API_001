<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Models\OrderMenuRestaurant;
use App\Models\PaymentRegulation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MainCouranteController extends Controller
{
    public function index(Request $request)
    {
        $auth = auth()->user();

        // 1. Période 1 : Gérée par le filtre "Filtrer par jour"
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

        // Application du N-1 uniquement si AUCUN filtre n'a été explicitement envoyé
        if (!$hasExplicitDate) {
            $dateP1 = Carbon::parse($dateP1)->subDay()->toDateString();
        }

        $dateDebutP1 = $dateP1;
        $dateFinP1   = $dateP1;

        // 2. Période 2 : Gérée par le "Filtrer par intervalle" (DE et À)
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

            $allSystemAmountDivers = $report_amount_p2;
            $all_p2_total_amount_divers = $report_amount_p2;

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
                'report_amount_p1' => $report_amount_p1,

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

                'all_p2_total_amount_divers' => $all_p2_total_amount_divers,
                'report_amount_p2' => $report_amount_p2,
                'report_amount' => $report_amount_p2,
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

        // 1. Période 1 : Gérée par le filtre "Filtrer par jour"
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

        // Application du N-1 uniquement si AUCUN filtre n'a été explicitement envoyé
        if (!$hasExplicitDate) {
            $dateP1 = Carbon::parse($dateP1)->subDay()->toDateString();
        }

        $dateDebutP1 = $dateP1;
        $dateFinP1   = $dateP1;

        // 2. Période 2 : Gérée par le "Filtrer par intervalle" (date_debut / date_fin)
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
            // Calcul des commandes globales système pour les montants divers non réglés / partiellement réglés
            $globalOrdersSystem = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereIn('regulation_status', [
                    PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                    PaymentOrderMenusStatus::NOT_PAID->value,
                ])
                ->with('payment')
                ->get();

            $allSystemAmountDivers = 0;
            $totalAlreadyPaid = 0;

            foreach ($globalOrdersSystem as $order) {
                if ($order->regulation_status === PaymentOrderMenusStatus::PARTIALLY_PAID->value) {
                    $amount = (float) ($order->remaining_amount ?? 0);
                } else {
                    $amount = (float) ($order->total_order ?? 0);
                }

                $paidOnThisOrder = (float) ($order->payment?->paid_amount ?? 0);
                $totalAlreadyPaid += $paidOnThisOrder;
                $allSystemAmountDivers += $amount;
            }

            $all_p2_total_amount_divers = $allSystemAmountDivers;

            // Fonction interne de calcul des métriques par période
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

            // --- CALCUL DES TOTAUX RESTAURANT (P1 et P2) ---
            $dinerKeyP1 = $dataP1['count_by_category']["DINER"] ?? $dataP1['count_by_category']["DINNER"] ?? 0;
            $dinerAmtP1 = $dataP1['totals_by_category']["DINER"] ?? $dataP1['totals_by_category']["DINNER"] ?? 0;

            $totalQtyJour = ($dataP1['count_by_category']["PETIT DEJEUNER"] ?? 0)
                + ($dataP1['count_by_category']["DEJEUNER"] ?? 0)
                + $dinerKeyP1
                + ($dataP1['total_quantity_room_service'] ?? 0)
                + ($dataP1['total_quantity_divers'] ?? 0);

            $totalAmtJour = ($dataP1['totals_by_category']["PETIT DEJEUNER"] ?? 0)
                + ($dataP1['totals_by_category']["DEJEUNER"] ?? 0)
                + $dinerAmtP1
                + ($dataP1['total_amount_room_service'] ?? 0)
                + ($dataP1['total_amount_divers'] ?? 0);

            $dinerKeyP2 = $dataP2['count_by_category']["DINER"] ?? $dataP2['count_by_category']["DINNER"] ?? 0;
            $dinerAmtP2 = $dataP2['totals_by_category']["DINER"] ?? $dataP2['totals_by_category']["DINNER"] ?? 0;

            $p2TotalQty = ($dataP2['count_by_category']["PETIT DEJEUNER"] ?? 0)
                + ($dataP2['count_by_category']["DEJEUNER"] ?? 0)
                + $dinerKeyP2
                + ($dataP2['total_quantity_room_service'] ?? 0)
                + ($dataP2['total_quantity_divers'] ?? 0);

            $p2TotalAmt = ($dataP2['totals_by_category']["PETIT DEJEUNER"] ?? 0)
                + ($dataP2['totals_by_category']["DEJEUNER"] ?? 0)
                + $dinerAmtP2
                + ($dataP2['total_amount_room_service'] ?? 0)
                + ($dataP2['total_amount_divers'] ?? 0);
            // ----------------------------------------------


            \Log::info('DEBUG SITUATION SHEET DATES', [
                'dateDebutP1' => $dateDebutP1,
                'dateFinP1'   => $dateFinP1,
                'dateDebutP2' => $dateDebutP2,
                'dateFinP2'   => $dateFinP2,
            ]);

            $dateFinP1Formatted = mb_strtoupper(Carbon::parse($dateFinP1)->locale('fr')->isoFormat('D MMMM YYYY'));
            $dateDebutP2Formatted = mb_strtoupper(Carbon::parse($dateDebutP2)->locale('fr')->isoFormat('D MMMM YYYY'));
            $dateFinP2Formatted = mb_strtoupper(Carbon::parse($dateFinP2)->locale('fr')->isoFormat('D MMMM YYYY'));

            $dynamicTitle = "FEUILLE DE SITUATION DU " . $dateFinP1Formatted . " - INTERVALLE DU " . $dateDebutP2Formatted . " AU " . $dateFinP2Formatted;

            $data = [
                'success' => true,
                'title' => $dynamicTitle,
                'date' => $dateDebutP1,
                'start_date' => $dateDebutP2,
                'end_date' => $dateFinP2,
                'periode_1' => ['date_debut' => $dateDebutP1, 'date_fin' => $dateFinP1],
                'periode_2' => ['date_debut' => $dateDebutP2, 'date_fin' => $dateFinP2],

                // Injection des totaux restaurant calculés
                'totalQtyJour' => $totalQtyJour,
                'totalAmtJour' => $totalAmtJour,
                'p2TotalQty' => $p2TotalQty,
                'p2TotalAmt' => $p2TotalAmt,

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

                'all_p2_total_amount_divers' => $all_p2_total_amount_divers,
            ];

            $fileName   = 'FEUILLE-DE-SITUATION-DU-' . $dateDebutP1 . '.pdf';
            $folderPath = 'storage/situation_sheets/' . now()->format('d-m-Y') . '/';
            $filePath   = $folderPath . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.situation_sheets.situation_sheets',
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
                'url'      => asset('storage/situation_sheets/' . now()->format('d-m-Y') . '/' . $fileName),
                'filename' => $fileName,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la génération du PDF de la situation.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}

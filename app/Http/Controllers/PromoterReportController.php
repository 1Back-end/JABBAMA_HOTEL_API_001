<?php

namespace App\Http\Controllers;

use App\Enums\CaisseType;
use App\Enums\DebtorsSummaryResponse;
use App\Enums\ExpenseSlug;
use App\Enums\KpiAnnualSummaryResponse;
use App\Enums\MenuOrderStatus;
use App\Enums\MetricKeyResponse;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\PaymentRegulationSlug;
use App\Enums\PdgCategory;
use App\Enums\RestaurantExpenseSlug;
use App\Enums\RestaurantSummaryMode;
use App\Enums\RestaurantSummaryResponse;
use App\Models\ExpensePayment;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
use App\Models\PaymentLine;
use App\Models\PaymentRegulation;
use App\Models\RegulationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PromoterReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataForDay = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$dayStart, $dayEnd])->exists();

        if (!$hasDataForDay && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json(KpiAnnualSummaryResponse::values());
        }

        $startOfYear = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $endOfYear   = $parsedDate->copy()->endOfYear()->toDateTimeString();

        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
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

        $totalBar = (float) $orders->sum('total_drinks');

        $totalAmountRoomService = (float) $orders->where('is_room_service', true)->sum(function ($order) {
            $price = (float) str_replace(',', '.', $order->price_for_room_service ?? 0);
            $quantity = (int) ($order->quantity_for_room_service ?? 0);
            return $price * $quantity;
        });

        $totalAmountDivers = 0;
        foreach ($orders as $order) {
            $uniqueItems = $order->items->unique('uuid');
            $validItems = $uniqueItems->filter(function ($item) {
                return $item->menu && (bool) $item->menu->is_generated_from_complement === true;
            });
            $totalAmountDivers += (float) $validItems->sum(function ($item) {
                return $item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));
            });
        }

        $chiffreAffaireAnnuel = (float) $categoriesTotals->sum()
            + (float) $totalBar
            + (float) $totalAmountRoomService
            + (float) $totalAmountDivers;

        $totalEncaissement = (float) PaymentRegulation::whereIn('slug', [
            PaymentRegulationSlug::ENCAISSEMENT_RESTO->value,
            PaymentRegulationSlug::ENCAISSEMENT_BAR->value,
        ])
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->sum('amount');

        $tauxEncaissement = $chiffreAffaireAnnuel > 0
            ? round(($totalEncaissement / $chiffreAffaireAnnuel) * 100, 2)
            : 0;

        $chargesAnnuelles = (float) PaymentRegulation::whereIn('slug', ExpenseSlug::values())
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->sum('amount');

        $tauxDepense = $chiffreAffaireAnnuel > 0
            ? round(($chargesAnnuelles / $chiffreAffaireAnnuel) * 100, 2)
            : 0;

        $margeBruteAnnuelle = $chiffreAffaireAnnuel - $chargesAnnuelles;

        $tauxMargeBrute = $chiffreAffaireAnnuel > 0
            ? round(100 - $tauxDepense, 2)
            : 0;

        return response()->json([
            'chiffre_affaire_annuel' => $chiffreAffaireAnnuel,
            'encaissement'           => $totalEncaissement,
            'taux_encaissement'      => $tauxEncaissement,
            'charges_annuelles'      => $chargesAnnuelles,
            'taux_depense'           => $tauxDepense,
            'marge_brute_annuelle'   => $margeBruteAnnuelle,
            'taux_marge_brute'       => $tauxMargeBrute,
        ]);
    }

    public function getRestaurantSummary(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataForDay = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->exists();

        if (!$hasDataForDay && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => RestaurantSummaryResponse::values(),
                'mois'  => RestaurantSummaryResponse::values(),
                'annee' => RestaurantSummaryResponse::values(),
            ]);
        }

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => $this->calculateMetricsForPeriod($dayStart, $dayEnd),
            'mois'  => $this->calculateMetricsForPeriod($monthStart, $monthEnd),
            'annee' => $this->calculateMetricsForPeriod($yearStart, $yearEnd),
        ]);
    }

    /**
     * Calcule le CA, l'Encaissement, les Dépenses et le Solde pour une période donnée.
     */
    private function calculateMetricsForPeriod(string $startDate, string $endDate): array
    {
        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $categoriesTotals = $orders->groupBy(function ($order) {
            return $order->salesCategory ? strtoupper(trim($order->salesCategory->name)) : 'AUTRES';
        })->map(function ($group) {
            return (float) $group->sum(function ($order) {
                return $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                })->sum('total_price');
            });
        });

        $totalAmountRoomService = (float) $orders->where('is_room_service', true)->sum(function ($order) {
            $price = (float) str_replace(',', '.', $order->price_for_room_service ?? 0);
            $quantity = (int) ($order->quantity_for_room_service ?? 0);
            return $price * $quantity;
        });

        $totalAmountDivers = 0;
        foreach ($orders as $order) {
            $uniqueItems = $order->items->unique('uuid');
            $validItems = $uniqueItems->filter(function ($item) {
                return $item->menu && (bool) $item->menu->is_generated_from_complement === true;
            });
            $totalAmountDivers += (float) $validItems->sum(function ($item) {
                return $item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));
            });
        }

        $sumCategories = (float) $categoriesTotals->sum();
        $chiffreAffaire = $sumCategories + $totalAmountRoomService + $totalAmountDivers;

        $encaissement = (float) PaymentRegulation::whereIn('slug', [
            PaymentRegulationSlug::ENCAISSEMENT_RESTO->value,
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        $depenses = (float) PaymentRegulation::where('slug', ExpenseSlug::DepensesResto->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        $solde = $encaissement - $depenses;

        return [
            'chiffre_affaire' => $chiffreAffaire,
            'encaissement'    => $encaissement,
            'depenses'        => $depenses,
            'solde'           => $solde,
        ];
    }

    public function getBarSummary(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataForDay = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->exists();

        if (!$hasDataForDay && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => RestaurantSummaryResponse::values(),
                'mois'  => RestaurantSummaryResponse::values(),
                'annee' => RestaurantSummaryResponse::values(),
            ]);
        }

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => $this->calculateBarMetricsForPeriod($dayStart, $dayEnd),
            'mois'  => $this->calculateBarMetricsForPeriod($monthStart, $monthEnd),
            'annee' => $this->calculateBarMetricsForPeriod($yearStart, $yearEnd),
        ]);
    }

    /**
     * Calcule le CA, l'Encaissement, les Dépenses et le Solde du Bar pour une période donnée.
     */
    private function calculateBarMetricsForPeriod(string $startDate, string $endDate): array
    {

        $orders = OrderMenuRestaurant::with('drinks')
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $chiffreAffaireBar = (float) $orders->sum('total_drinks');

        $encaissement = (float) PaymentRegulation::where('slug', PaymentRegulationSlug::ENCAISSEMENT_BAR->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        $depenses = (float) PaymentRegulation::where('slug', ExpenseSlug::DepensesBar->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        $solde = $encaissement - $depenses;

        return [
            'chiffre_affaire' => $chiffreAffaireBar,
            'encaissement'    => $encaissement,
            'depenses'        => $depenses,
            'solde'           => $solde,
        ];
    }

    /**
     * Récupère le résumé des AUTRES ENCAISSEMENTS PDG pour la journée, le mois et l'année.
     */
    public function getAutresEncaissements(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataForDay = PaymentRegulation::where('slug', PdgCategory::AUTRES_ENCAISSEMENTS->value)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->exists();

        if (!$hasDataForDay && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, 0),
                'mois'  => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, 0),
                'annee' => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, 0),
            ]);
        }

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $dayStart, $dayEnd)),
            'mois'  => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $monthStart, $monthEnd)),
            'annee' => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $yearStart, $yearEnd)),
        ]);
    }

    /**
     * Calcule le total des encaissements depuis la table PaymentRegulation par slug.
     */
    private function calculateOtherIncomesMetrics(PdgCategory $category, string $startDate, string $endDate): float
    {
        return (float) PaymentRegulation::where('slug', $category->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');
    }

    public function getPdgExpenses(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataForDay = PaymentRegulation::where('slug', PdgCategory::AUTRES_DEPENSES->value)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->exists();

        if (!$hasDataForDay && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, 0),
                'mois'  => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, 0),
                'annee' => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, 0),
            ]);
        }

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $dayStart, $dayEnd)),
            'mois'  => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $monthStart, $monthEnd)),
            'annee' => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $yearStart, $yearEnd)),
        ]);
    }

    /**
     * Calcule le total des dépenses PDG depuis la table appropriée (ex: PaymentRegulation ou Expense).
     */
    private function calculatePdgExpensesMetrics(PdgCategory $category, string $startDate, string $endDate): float
    {
        return (float) PaymentRegulation::where('slug', $category->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');
    }

    public function getPaymentMethodsSummary(Request $request): JsonResponse
    {
        if ($request->filled('date')) {
            $parsedDate = Carbon::createFromFormat('d-m-Y', $request->date);
            $startOfPeriod = $parsedDate->copy()->startOfDay()->toDateTimeString();
            $endOfPeriod   = $parsedDate->copy()->endOfDay()->toDateTimeString();
        } else {
            $parsedDate = Carbon::now();
            $startOfPeriod = $parsedDate->copy()->startOfYear()->toDateTimeString();
            $endOfPeriod   = $parsedDate->copy()->endOfYear()->toDateTimeString();
        }

        $methods = RegulationMethod::where('active', true)->get();

        $encaissementSlugs = array_merge(
            PaymentRegulationSlug::values(),
            [PdgCategory::AUTRES_ENCAISSEMENTS->value]
        );

        $expenseSlugs = array_merge(
            ExpenseSlug::values(),
            [PdgCategory::AUTRES_DEPENSES->value]
        );

        $regulations = PaymentRegulation::whereBetween('created_at', [$startOfPeriod, $endOfPeriod])
            ->get()
            ->groupBy('regulation_method_uuid');

        $details = [];
        $soldeGlobalTotal = 0;

        foreach ($methods as $method) {
            $methodRegulations = $regulations->get($method->uuid, collect());

            $encaissements = (float) $methodRegulations
                ->whereIn('slug', $encaissementSlugs)
                ->sum('amount');

            $depenses = (float) $methodRegulations
                ->whereIn('slug', $expenseSlugs)
                ->sum('amount');

            $solde = $encaissements - $depenses;
            $soldeGlobalTotal += $solde;

            $formattedLabel = CaisseType::formatLabel($method->name);

            $details[] = [
                'uuid'          => $method->uuid,
                'code'          => $method->code,
                'label'         => $formattedLabel,
                'encaissements' => $encaissements,
                'depenses'      => $depenses,
                'solde'         => $solde,
            ];
        }

        return response()->json([
            'solde_total' => $soldeGlobalTotal,
            'caisses'     => $details,
        ]);
    }

    public function getDebtorsSummary(Request $request): JsonResponse
    {
        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasData = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
            ->whereIn('regulation_status', [
                PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                PaymentOrderMenusStatus::NOT_PAID->value,
            ])
            ->exists();

        if (!$hasData && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour' => 0.0
            ]);
        }

        return response()->json([
            'jour' => $this->calculateAllDebtorsMetrics(),
        ]);
    }

    private function calculateAllDebtorsMetrics(): float
    {
        $orders = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
            ->whereIn('regulation_status', [
                PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                PaymentOrderMenusStatus::NOT_PAID->value,
            ])
            ->get();

        $totalDebtors = 0;

        foreach ($orders as $order) {
            $totalDebtors += ($order->regulation_status === PaymentOrderMenusStatus::PARTIALLY_PAID->value)
                ? (float) ($order->remaining_amount ?? 0)
                : (float) ($order->total_order ?? 0);
        }

        return (float) $totalDebtors;
    }
    public function getDetailedSalesSummary(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => $this->calculateDetailedMetricsForPeriod($dayStart, $dayEnd),
            'mois'  => $this->calculateDetailedMetricsForPeriod($monthStart, $monthEnd),
            'annee' => $this->calculateDetailedMetricsForPeriod($yearStart, $yearEnd),
        ]);
    }

    /**
     * Calcule le détail par descriptif (Dîner, Bar, Petit déjeuner, Divers, Déjeuner, Room Service) pour une période.
     */
    private function calculateDetailedMetricsForPeriod(string $startDate, string $endDate): array
    {
        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $categoriesTotals = $orders->groupBy(function ($order) {
            return $order->salesCategory ? strtoupper($order->salesCategory->name) : 'AUTRES';
        })->map(function ($group) {
            return (float) $group->sum(function ($order) {
                return $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                })->sum('total_price');
            });
        })->toArray();

        $totalAmountRoomService = (float) $orders->where('is_room_service', true)->sum(function ($order) {
            $price = (float) str_replace(',', '.', $order->price_for_room_service ?? 0);
            $quantity = (int) ($order->quantity_for_room_service ?? 0);
            return $price * $quantity;
        });

        $totalAmountDivers = 0;
        foreach ($orders as $order) {
            $uniqueItems = $order->items->unique('uuid');
            $validItems = $uniqueItems->filter(function ($item) {
                return $item->menu && (bool) $item->menu->is_generated_from_complement === true;
            });
            $totalAmountDivers += (float) $validItems->sum(function ($item) {
                return $item->total_price ?? (($item->unit_price ?? 0) * ($item->quantity_exactly ?? 0));
            });
        }

        $result = $categoriesTotals;
        $result['ROOM SERVICE'] = $totalAmountRoomService;
        $result['DIVERS RESTAURANT'] = $totalAmountDivers;

        return $result;
    }

    public function getRestaurantCashReceiptItems(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('limit', 10);
        $page    = (int) $request->input('page', 1);

        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        // Définition des plages de dates
        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        // 1. Récupération paginée pour le JOUR (avec tous les détails)
        $paginatedLines = PaymentLine::with(['method', 'payment.order'])
            ->whereIn('payable_type', [
                \App\Models\OrderMenuRestaurantItem::class,
                \App\Models\RoomService::class,
            ])
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        // 2. Récupération uniquement de la somme pour le MOIS
        $monthTotal = $this->fetchTotalByDateRange($monthStart, $monthEnd);

        // 3. Récupération uniquement de la somme pour l'ANNÉE
        $yearTotal = $this->fetchTotalByDateRange($yearStart, $yearEnd);

        return response()->json([
            'status' => 'success',
            'jour'   => [
                'data'         => $this->formatReceiptItems($paginatedLines->items()),
                'current_page' => $paginatedLines->currentPage(),
                'last_page'    => $paginatedLines->lastPage(),
                'total'        => $paginatedLines->total(),
            ],
            'mois'   => [
                'total' => $monthTotal,
            ],
            'annee'  => [
                'total' => $yearTotal,
            ],
        ]);
    }

    /**
     * Fonction auxiliaire pour récupérer uniquement le total des montants sur une période
     */
    protected function fetchTotalByDateRange(string $start, string $end): float
    {
        return (float) PaymentLine::whereIn('payable_type', [
            \App\Models\OrderMenuRestaurantItem::class,
            \App\Models\RoomService::class,
        ])
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
    }

    /**
     * Helper partagé pour le formatage des lignes du jour
     */
    protected function formatReceiptItems($lines): array
    {
        return collect($lines)->map(function ($line) {
            $description = 'Encaissement';

            if ($line->payable_type === \App\Models\OrderMenuRestaurantItem::class) {
                $restaurantItem = \App\Models\OrderMenuRestaurantItem::with('menu')->find($line->payable_uuid);
                $description = optional($restaurantItem?->menu)->name
                    ?? optional($restaurantItem)->name
                    ?? 'Encaissement Restaurant';
            } elseif ($line->payable_type === \App\Models\RoomService::class) {
                $roomServiceItem = \App\Models\RoomService::find($line->payable_uuid);
                $description = optional($roomServiceItem)->name
                    ?? 'Room Service';
            }

            $orderCode = optional(optional($line->payment)->order)->code ?? '';

            return [
                'uuid'        => $line->uuid,
                'code'        => $orderCode,
                'description' => $description,
                'amount'      => (float) $line->amount,
                'method'      => optional($line->method)->name ?? '',
            ];
        })->toArray();
    }


    public function get_expenses(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        $allowedSlugs = [RestaurantExpenseSlug::RESTO->value];
        $createdBy    = $request->input('created_by', null);

        return response()->json([
            'status' => 'success',
            'jour'   => $this->fetchExpensesByDateRange($dayStart, $dayEnd, $createdBy, $allowedSlugs),
            'mois'   => $this->fetchExpensesByDateRange($monthStart, $monthEnd, $createdBy, $allowedSlugs),
            'annee'  => $this->fetchExpensesByDateRange($yearStart, $yearEnd, $createdBy, $allowedSlugs),
        ]);
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
                        'items'    => [],
                    ];
                }

                $current[$defaultKey]['amount'] += (float) $item->amount;
                $current[$defaultKey]['items'][] = [
                    'uuid'   => $item->uuid,
                    'name'   => $item->name,
                    'amount' => (float) $item->amount,
                    'method' => $item->method,
                ];

                continue;
            }

            // Trier la hiérarchie par niveau
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
                        'items'    => [],
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
                        'items'    => [],
                    ];
                }

                $current[$family->uuid]['amount'] += (float) $item->amount;

                $current[$family->uuid]['items'][] = [
                    'uuid'   => $item->uuid,
                    'name'   => $item->name,
                    'amount' => (float) $item->amount,
                    'method' => $item->method,
                ];
            } else {
                $current_key = 'direct_' . $item->uuid;
                $current[$current_key] = [
                    'uuid'     => $item->uuid,
                    'name'     => $item->name,
                    'amount'   => (float) $item->amount,
                    'children' => [],
                    'items'    => [
                        [
                            'uuid'   => $item->uuid,
                            'name'   => $item->name,
                            'amount' => (float) $item->amount,
                            'method' => $item->method,
                        ]
                    ],
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
            ->whereIn(DB::raw('UPPER(slug)'), $allowedSlugs);

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
                    'expense_type' => optional($firstItem)->expenseType,
                    'title'        => 'DEPENSES ' . $slug,
                    'total_amount' => (float) $items->sum('amount'),
                    'families'     => $families,
                    'isLoading'    => false,
                ];
            })
            ->values();
    }

    public function getSalesCategoriesSummary(Request $request)
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd   = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->get();

        $groupedOrders = $orders->groupBy(function ($order) {
            return $order->salesCategory ? $order->salesCategory->name : 'AUTRES';
        });

        $categoriesCounts = $groupedOrders->map(function ($group) {
            return (int) $group->sum(function ($order) {
                return $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                })->sum('quantity_exactly');
            });
        });

        $totalQuantityDivers = 0;
        $totalAmountDivers = 0;

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

        if ($totalQuantityDivers > 0) {
            $categoriesCounts->put('DIVERS', $totalQuantityDivers);
        }

        $totalFinal = $categoriesCounts->sum();

        return response()->json([
            'success'      => true,
            'date'         => $parsedDate->format('d-m-Y'),
            'data'         => $categoriesCounts,
            'total_global' => $totalFinal
        ]);
    }




}

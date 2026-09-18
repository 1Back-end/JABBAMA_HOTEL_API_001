<?php

namespace App\Http\Controllers;

use App\Enums\CaisseType;
use App\Enums\DebtorsSummaryResponse;
use App\Enums\ExpenseSlug;
use App\Enums\KpiAnnualSummaryResponse;
use App\Enums\MenuOrderStatus;
use App\Enums\MetricKeyResponse;
use App\Enums\PaymentOrderItemStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\PaymentRegulationSlug;
use App\Enums\PaymentStatus;
use App\Enums\PdgCategory;
use App\Enums\RestaurantExpenseSlug;
use App\Enums\RestaurantRubricEnum;
use App\Enums\RestaurantSummaryMode;
use App\Enums\RestaurantSummaryResponse;
use App\Models\ExpensePayment;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
use App\Models\PaymentLine;
use App\Models\PaymentRegulation;
use App\Models\RegulationMethod;
use App\Models\RoomService;
use App\Models\SalesCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class PromoterReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $startOfYear = $parsedDate->copy()->startOfYear()->toDateTimeString();

        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json(KpiAnnualSummaryResponse::values());
        }

        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
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
            ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
            ->sum('amount');

        $tauxEncaissement = $chiffreAffaireAnnuel > 0
            ? round(($totalEncaissement / $chiffreAffaireAnnuel) * 100, 2)
            : 0;

        $chargesAnnuelles = (float) PaymentRegulation::whereIn('slug', ExpenseSlug::values())
            ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
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

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => RestaurantSummaryResponse::values(),
                'mois'  => RestaurantSummaryResponse::values(),
                'annee' => RestaurantSummaryResponse::values(),
            ]);
        }

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $startOfYear;
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString(); // ou $currentDateEnd selon si vous voulez l'année complète ou s'arrêter à la date du jour

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

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => RestaurantSummaryResponse::values(),
                'mois'  => RestaurantSummaryResponse::values(),
                'annee' => RestaurantSummaryResponse::values(),
            ]);
        }

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $startOfYear;
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

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = PaymentRegulation::where('slug', PdgCategory::AUTRES_ENCAISSEMENTS->value)
            ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
            ->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, 0),
                'mois'  => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, 0),
                'annee' => MetricKeyResponse::format(MetricKeyResponse::ENCAISSEMENT, 0),
            ]);
        }

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $startOfYear;
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

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = PaymentRegulation::where('slug', PdgCategory::AUTRES_DEPENSES->value)
            ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
            ->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, 0),
                'mois'  => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, 0),
                'annee' => MetricKeyResponse::format(MetricKeyResponse::DEPENSES, 0),
            ]);
        }

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $startOfYear;
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
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            $methods = RegulationMethod::where('active', true)->get();
            $emptyDetails = $methods->map(function ($method) {
                return [
                    'uuid'          => $method->uuid,
                    'code'          => $method->code,
                    'label'         => CaisseType::formatLabel($method->name),
                    'encaissements' => 0.0,
                    'depenses'      => 0.0,
                    'solde'         => 0.0,
                ];
            });

            return response()->json([
                'solde_total' => 0.0,
                'caisses'     => $emptyDetails,
            ]);
        }

        $startOfPeriod = $startOfYear;
        $endOfPeriod   = $currentDateEnd;

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

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => [],
                'mois'  => [],
                'annee' => [],
            ]);
        }

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $startOfYear;
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

        $categoriesTotals = [];

        $groupedBySalesCategory = $orders->groupBy(function ($order) {
            return $order->salesCategory ? strtoupper($order->salesCategory->name) : 'AUTRES';
        });

        foreach ($groupedBySalesCategory as $name => $group) {
            $uuid = optional($group->first()->salesCategory)->uuid;

            $total = (float) $group->sum(function ($order) {
                return $order->items->filter(function ($item) {
                    return $item->menu && !$item->menu->is_generated_from_complement;
                })->sum('total_price');
            });

            $categoriesTotals[$name] = [
                'uuid'   => $uuid,
                'amount' => $total
            ];
        }

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

        $result['ROOM SERVICE'] = [
            'uuid'   => null,
            'amount' => $totalAmountRoomService
        ];

        $result['DIVERS RESTAURANT'] = [
            'uuid'   => null,
            'amount' => $totalAmountDivers
        ];

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

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'success'      => true,
                'date'         => $parsedDate->format('d-m-Y'),
                'data'         => [],
                'total_global' => 0
            ]);
        }

        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
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


    public function getTurnoverDetails(Request $request)
    {
        $rubricInput = $request->input('rubric');
        $rawDate = $request->input('date', Carbon::today()->toDateString());

        try {
            $date = Carbon::createFromFormat('d-m-Y', $rawDate)->toDateString();
        } catch (\Exception $e) {
            $date = Carbon::parse($rawDate)->toDateString();
        }

        $salesCategory = $rubricInput ? SalesCategory::find($rubricInput) : null;
        $rubricName = $salesCategory ? strtoupper(trim($salesCategory->name)) : ($rubricInput ? strtoupper(trim($rubricInput)) : null);

        $isRoomService = ($rubricName === RestaurantRubricEnum::ROOM_SERVICE->value);

        $itemsData = collect();

        if (!$isRoomService) {
            $restoQuery = OrderMenuRestaurantItem::with([
                'menu',
                'order.salesCategory',
                'order.payment'
            ])
                ->whereHas('order', function ($orderQuery) use ($date, $salesCategory, $rubricInput, $rubricName) {
                    $orderQuery->whereDate('created_at', $date);

                    if ($salesCategory) {
                        $orderQuery->where('sales_category_uuid', $salesCategory->uuid);
                    } elseif ($rubricInput && $rubricName !== RestaurantRubricEnum::DIVERS_RESTAURANT->value) {
                        $orderQuery->where('sales_category_uuid', $rubricInput);
                    }
                });

            if ($rubricName === RestaurantRubricEnum::DIVERS_RESTAURANT->value) {
                $restoQuery->whereHas('menu', function ($menuQuery) {
                    $menuQuery->where('is_generated_from_complement', true);
                });
            } else {
                $restoQuery->whereHas('menu', function ($menuQuery) {
                    $menuQuery->where('is_generated_from_complement', false);
                });
            }

            $restoItems = $restoQuery->get()->map(function ($item) {
                $menuName = optional($item->menu)->name ?? $item->name;
                $orderCode = optional($item->order)->code ?? '';
                $salesCategoryName = optional(optional($item->order)->salesCategory)->name;
                $methodName = $item->status_payment_label;

                return [
                    'uuid'           => $item->uuid,
                    'code'           => $orderCode,
                    'description'    => $menuName,
                    'menu_name'      => $menuName,
                    'sales_category' => $salesCategoryName,
                    'amount'         => (float) ($item->total_price ?? ($item->quantity * $item->unit_price) ?? 0),
                    'method'         => $methodName,
                    'created_at'     => optional($item->order)->created_at ? optional($item->order)->created_at->toDateTimeString() : $item->created_at->toDateTimeString(),
                ];
            });

            $itemsData = $itemsData->concat($restoItems);
        }

        if (!$rubricInput || $isRoomService) {
            $roomServiceQuery = OrderMenuRestaurant::with(['items.menu'])
                ->where('is_room_service', true)
                ->whereDate('created_at', $date)
                ->get()
                ->map(function ($item) {
                    $menuName = optional($item->menu)->name ?? $item->description ?? 'Room Service';
                    $orderCode = optional($item->order)->code ?? $item->code ?? '';

                    $unitPrice = (float) ($item->price_for_room_service ?? $item->total_price ?? 0);
                    $quantity  = (int) ($item->quantity_for_room_service ?? 1);
                    $calculatedAmount = $unitPrice * $quantity;

                    return [
                        'uuid'           => $item->uuid,
                        'code'           => $orderCode,
                        'description'    => $menuName,
                        'menu_name'      => $menuName,
                        'sales_category' => 'ROOM SERVICE',
                        'amount'                    => $calculatedAmount,
                        'method'         => $item->global_status_label,
                        'created_at'     => $item->created_at ? $item->created_at->toDateTimeString() : now()->toDateTimeString(),
                    ];
                });

            if ($isRoomService) {
                $itemsData = $roomServiceQuery;
            } else {
                $itemsData = $itemsData->concat($roomServiceQuery);
            }
        }

        return response()->json([
            'success' => true,
            'date_parsed' => $date,
            'rubric_input' => $rubricInput,
            'total_lines_found_for_date' => $itemsData->count(),
            'data'    => $itemsData->values()
        ]);
    }

    public function getPaidDetailedSalesSummary(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $startOfYear    = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $mode = RestaurantSummaryMode::ZERO_ON_EMPTY;

        $hasDataUpToDate = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
                ->whereBetween('created_at', [$startOfYear, $currentDateEnd])
                ->exists()
            || PaymentRegulation::whereBetween('created_at', [$startOfYear, $currentDateEnd])->exists();

        if (!$hasDataUpToDate && $mode === RestaurantSummaryMode::ZERO_ON_EMPTY) {
            return response()->json([
                'jour'  => [],
                'mois'  => [],
                'annee' => [],
            ]);
        }

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $startOfYear;
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => $this->calculatePaidDetailedMetricsForPeriod($dayStart, $dayEnd),
            'mois'  => $this->calculatePaidDetailedMetricsForPeriod($monthStart, $monthEnd),
            'annee' => $this->calculatePaidDetailedMetricsForPeriod($yearStart, $yearEnd),
        ]);
    }

    /**
     * Calcule le détail par descriptif (Dîner, Bar, Petit déjeuner, Divers, Déjeuner, Room Service) pour une période
     * en se basant sur les lignes de paiement (PaymentLine).
     */
    private function calculatePaidDetailedMetricsForPeriod(string $startDate, string $endDate): array
    {
        $paymentLines = PaymentLine::with([
            'item.menu:uuid,is_generated_from_complement',
            'item.order.salesCategory:uuid,name,code',
            'roomService'
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $categoriesTotals = [];
        $totalAmountRoomService = 0;
        $totalAmountDivers = 0;

        foreach ($paymentLines as $line) {
            if ($line->roomService || str_contains(strtolower($line->payable_type ?? ''), 'roomservice')) {
                $totalAmountRoomService += (float) $line->amount;
                continue;
            }

            if ($line->item) {
                $item = $line->item;
                $isComplement = optional($item->menu)->is_generated_from_complement ?? false;

                if ($isComplement) {
                    $totalAmountDivers += (float) $line->amount;
                } else {
                    $salesCategory = optional(optional(optional($item->order)->salesCategory));
                    $categoryName = $salesCategory->name ? strtoupper($salesCategory->name) : 'AUTRES';
                    $categoryUuid = $salesCategory->uuid ?? null;

                    if (!isset($categoriesTotals[$categoryName])) {
                        $categoriesTotals[$categoryName] = [
                            'uuid'   => $categoryUuid,
                            'amount' => 0.0
                        ];
                    }

                    $categoriesTotals[$categoryName]['amount'] += (float) $line->amount;
                }
            }
        }

        $result = $categoriesTotals;

        $result['ROOM SERVICE'] = [
            'uuid'   => null,
            'amount' => $totalAmountRoomService
        ];

        $result['DIVERS RESTAURANT'] = [
            'uuid'   => null,
            'amount' => $totalAmountDivers
        ];

        return $result;
    }

    public function getTurnoverDetailsSalesPaid(Request $request)
    {
        $rubricInput = $request->input('rubric');
        $rawDate = $request->input('date', Carbon::today()->toDateString());

        try {
            $date = Carbon::createFromFormat('d-m-Y', $rawDate)->toDateString();
        } catch (\Exception $e) {
            $date = Carbon::parse($rawDate)->toDateString();
        }

        $salesCategory = $rubricInput ? SalesCategory::find($rubricInput) : null;
        $rubricName = $salesCategory ? strtoupper(trim($salesCategory->name)) : ($rubricInput ? strtoupper(trim($rubricInput)) : null);

        $paymentLines = PaymentLine::with([
            'payment.order.salesCategory',
            'method',
            'item.menu',
            'item.order.salesCategory',
            'roomService'
        ])
            ->whereDate('created_at', $date)
            ->where('slug', RestaurantExpenseSlug::RESTO->value)
            ->where(function ($query) use ($rubricName, $rubricInput, $salesCategory) {

                if ($rubricName === RestaurantRubricEnum::DIVERS_RESTAURANT->value) {
                    $query->where('payable_type', OrderMenuRestaurantItem::class)
                        ->whereHas('item.menu', function ($menuQuery) {
                            $menuQuery->where('is_generated_from_complement', true);
                        })
                        ->whereHas('item.order', function ($orderQuery) use ($salesCategory, $rubricInput) {
                            $orderQuery->where('status', MenuOrderStatus::FACTURATE->value);
                            if ($salesCategory) {
                                $orderQuery->where('sales_category_uuid', $salesCategory->uuid);
                            }
                        });

                } elseif ($rubricName === RestaurantRubricEnum::ROOM_SERVICE->value) {
                    $query->where('payable_type', RoomService::class);

                } else {
                    $query->where(function ($subQuery) use ($salesCategory, $rubricInput) {
                        $subQuery->where(function ($q) use ($salesCategory, $rubricInput) {
                            $q->whereIn('payable_type', [
                                OrderMenuRestaurantItem::class,
                            ])
                                ->where(function ($innerQuery) use ($salesCategory, $rubricInput) {
                                    $innerQuery->orWhere(function ($itemQuery) use ($salesCategory, $rubricInput) {
                                        $itemQuery->where('payable_type', OrderMenuRestaurantItem::class)
                                            ->whereHas('item.menu', function ($menuQuery) {
                                                $menuQuery->where('is_generated_from_complement', false);
                                            })
                                            ->whereHas('item.order', function ($orderQuery) use ($salesCategory, $rubricInput) {
                                                $orderQuery->where('status', MenuOrderStatus::FACTURATE->value);

                                                if ($salesCategory) {
                                                    $orderQuery->where('sales_category_uuid', $salesCategory->uuid);
                                                } elseif ($rubricInput) {
                                                    $orderQuery->where('sales_category_uuid', $rubricInput);
                                                }
                                            });
                                    })
                                        ->orWhere(function ($roomQuery) use ($salesCategory, $rubricInput) {
                                            $roomQuery->where('payable_type', RoomService::class);
                                        });
                                });
                        });

                        if (!$rubricInput) {
                            $subQuery->orWhere('payable_type', RoomService::class);
                        }
                    });
                }
            })
            ->get();

        $formattedLines = $paymentLines->map(function ($line) {
            $description = 'Encaissement Restaurant';
            $menuName = null;
            $salesCategoryName = null;
            $quantity = 1;
            $amount = (float) $line->amount;

            if ($line->payable_type === OrderMenuRestaurantItem::class) {
                $restaurantItem = $line->item;
                $menuName = optional($restaurantItem?->menu)->name
                    ?? optional($restaurantItem)->name;
                $description = $menuName ?? 'Encaissement Restaurant';

                $salesCategoryName = optional(optional($restaurantItem?->order)?->salesCategory)->name;
                $quantity = (int) ($restaurantItem?->quantity ?? 1);

            } elseif ($line->payable_type === RoomService::class) {
                $roomServiceItem = $line->roomService;
                $menuName = optional($roomServiceItem)->name ?? optional($roomServiceItem)->description ?? 'Room Service';
                $description = $menuName;

                $quantity = (int) ($roomServiceItem?->quantity_for_room_service ?? $roomServiceItem?->quantity ?? 1);

                $unitPrice = (float) ($roomServiceItem?->price_for_room_service ?? $roomServiceItem?->total_price ?? 0);
                if ($unitPrice > 0 && $amount <= 0) {
                    $amount = $unitPrice * $quantity;
                }
            }

            $orderCode = optional(optional($line->payment)->order)->code ?? '';

            return [
                'uuid'                      => $line->uuid,
                'code'                      => $orderCode,
                'description'               => $description,
                'menu_name'                 => $menuName,
                'sales_category'            => $salesCategoryName,
                'quantity_for_room_service' => $quantity,
                'amount'                    => $amount,
                'method'                    => optional($line->method)->name ?? '',
                'created_at'                => $line->created_at->toDateTimeString(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'date_parsed' => $date,
            'rubric_input' => $rubricInput,
            'total_lines_found_for_date' => $formattedLines->count(),
            'data'    => $formattedLines
        ]);
    }


}

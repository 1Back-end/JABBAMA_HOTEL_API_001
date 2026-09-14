<?php

namespace App\Http\Controllers;

use App\Enums\CaisseType;
use App\Enums\ExpenseSlug;
use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\PaymentRegulationSlug;
use App\Enums\PdgCategory;
use App\Models\OrderMenuRestaurant;
use App\Models\PaymentRegulation;
use App\Models\RegulationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PromoterReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

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

        $chiffreAffaire = (float) $categoriesTotals->sum()
            + (float) $totalBar
            + (float) $totalAmountRoomService
            + (float) $totalAmountDivers;

        $encaissement = (float) PaymentRegulation::where('slug', PaymentRegulationSlug::ENCAISSEMENT_RESTO->value)
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

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $dayStart, $dayEnd),
            'mois'  => $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $monthStart, $monthEnd),
            'annee' => $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $yearStart, $yearEnd),
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

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $parsedDate->copy()->endOfYear()->toDateTimeString();

        return response()->json([
            'jour'  => $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $dayStart, $dayEnd),
            'mois'  => $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $monthStart, $monthEnd),
            'annee' => $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $yearStart, $yearEnd),
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
            'jour'  => $this->calculateDebtorsMetricsForPeriod($dayStart, $dayEnd),
            'mois'  => $this->calculateDebtorsMetricsForPeriod($monthStart, $monthEnd),
            'annee' => $this->calculateDebtorsMetricsForPeriod($yearStart, $yearEnd),
        ]);
    }
    private function calculateDebtorsMetricsForPeriod(string $startDate, string $endDate): float
    {
        $orders = OrderMenuRestaurant::where('status', MenuOrderStatus::FACTURATE->value)
            ->whereIn('regulation_status', [
                PaymentOrderMenusStatus::PARTIALLY_PAID->value,
                PaymentOrderMenusStatus::NOT_PAID->value,
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
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
}

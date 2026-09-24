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
use App\Models\CashReceiptFamily;
use App\Models\ExpensePayment;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use App\Models\OtherCashIn;
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
    /**
     * Calcule le résumé des caisses (par mode de paiement) pour une période donnée.
     */
    private function calculateCaisseSummaryForPeriod(string $startDate, string $endDate): array
    {
        $methods = RegulationMethod::where('active', true)->get();

        $encaissementSlugs = [
            PaymentRegulationSlug::ENCAISSEMENT_RESTO->value,
            PaymentRegulationSlug::ENCAISSEMENT_BAR->value,
            PdgCategory::AUTRES_ENCAISSEMENTS->value,
        ];

        $expenseSlugs = array_merge(
            ExpenseSlug::values(),
            [PdgCategory::AUTRES_DEPENSES->value]
        );

        $regulations = PaymentRegulation::whereBetween('created_at', [$startDate, $endDate])
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

        return [
            'solde_total' => $soldeGlobalTotal,
            'caisses'     => $details,
        ];
    }

    /**
     * Exporte le rapport du promoteur au format PDF pour une date donnée.
     */
    public function exportPromoterReportPdf(Request $request)
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $startOfYear = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $currentDateEnd = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $dayEnd;

        $yearStart  = $startOfYear;
        $yearEnd    = $currentDateEnd;

        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
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

        // Calcul des quantités de ventes (Restaurant & Bar) sur la période annuelle
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
        foreach ($orders as $order) {
            $uniqueItems = $order->items->unique('uuid');
            $validItems = $uniqueItems->filter(function ($item) {
                return $item->menu && (bool) $item->menu->is_generated_from_complement === true;
            });
            $totalQuantityDivers += (int) $validItems->sum('quantity_exactly');
        }

        if ($totalQuantityDivers > 0) {
            $categoriesCounts->put('DIVERS', $totalQuantityDivers);
        }

        $totalVentes = (int) $categoriesCounts->sum();

        $countBar = (int) $orders->sum(function ($order) {
            return $order->drinks->sum('quantity_exactly');
        });

        // Autres métriques périodiques
        $metricsJour  = $this->calculateMetricsForPeriod($dayStart, $dayEnd);
        $metricsMois  = $this->calculateMetricsForPeriod($monthStart, $monthEnd);
        $metricsAnnee = $this->calculateMetricsForPeriod($yearStart, $yearEnd);

        $barJour  = $this->calculateBarMetricsForPeriod($dayStart, $dayEnd);
        $barMois  = $this->calculateBarMetricsForPeriod($monthStart, $monthEnd);
        $barAnnee = $this->calculateBarMetricsForPeriod($yearStart, $yearEnd);

        $autresJour  = [
            'encaissement' => $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $dayStart, $dayEnd),
            'depenses'     => $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $dayStart, $dayEnd),
        ];
        $autresMois  = [
            'encaissement' => $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $monthStart, $monthEnd),
            'depenses'     => $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $monthStart, $monthEnd),
        ];
        $autresAnnee = [
            'encaissement' => $this->calculateOtherIncomesMetrics(PdgCategory::AUTRES_ENCAISSEMENTS, $yearStart, $yearEnd),
            'depenses'     => $this->calculatePdgExpensesMetrics(PdgCategory::AUTRES_DEPENSES, $yearStart, $yearEnd),
        ];
        $caisseJour = $this->calculateCaisseSummaryForPeriod($dayStart, $dayEnd);

        $formattedFolderDate = now()->format('d-m-Y');
        $fileName   = strtoupper('RAPPORT-DU-PROMOTEUR-N-’' . now()->format('YmdHis')) . '.pdf';
        $folderPath = 'storage/promoter-reports/' . now()->format('d-m-Y') . '/';
        $filePath   = $folderPath . '/' . $fileName;

        if (!is_dir($folderPath)) {
            if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                throw new \RuntimeException("Impossible de créer le répertoire : {$folderPath}");
            }
        }

        $data = [
            'parsedDate'             => $parsedDate,
            'chiffre_affaire_annuel' => $chiffreAffaireAnnuel,
            'encaissement'           => $totalEncaissement,
            'taux_encaissement'      => $tauxEncaissement,
            'charges_annuelles'      => $chargesAnnuelles,
            'taux_depense'           => $tauxDepense,
            'marge_brute_annuelle'   => $margeBruteAnnuelle,
            'taux_marge_brute'       => $tauxMargeBrute,
            'totalVentes'            => $totalVentes,
            'metricsJour'            => $metricsJour,
            'metricsMois'            => $metricsMois,
            'metricsAnnee'           => $metricsAnnee,
            'barJour'                => $barJour,
            'barMois'                => $barMois,
            'barAnnee'               => $barAnnee,
            'autresJour'             => $autresJour,
            'autresMois'             => $autresMois,
            'autresAnnee'            => $autresAnnee,
            'caisseJour'             => $caisseJour,
            'total_bar'              => $totalBar,
            'count_bar'              => $countBar,
        ];

        $footer = 'pdfs.reports.factures.footer';

        save_browser_shot_pdf(
            view: 'pdfs.promoter-reports.promoter-reports',
            data: $data,
            folderPath: $folderPath,
            path: $filePath,
            format: 'A4',
            margins: [5, 5, 5, 5],
            footer: $footer
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
            'url'      => asset('storage/promoter-reports/' . $formattedFolderDate . '/' . $fileName),
            'filename' => $fileName,
        ], 200);
    }


    /**
     * Calcule et retourne les indicateurs clés de performance (KPI) annuels du résumé des caisses pour une date donnée.
     */
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

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

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

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

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
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

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

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

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

        $startOfDay = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $endOfDay   = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $methods = RegulationMethod::where('active', true)->get();

        $encaissementSlugs = array_merge(
            PaymentRegulationSlug::values(),
            [PdgCategory::AUTRES_ENCAISSEMENTS->value]
        );

        $expenseSlugs = array_merge(
            ExpenseSlug::values(),
            [PdgCategory::AUTRES_DEPENSES->value]
        );

        $regulations = PaymentRegulation::whereBetween('created_at', [$startOfDay, $endOfDay])
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
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

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
        $perPage = (int) $request->input('limit', 30);
        $page    = (int) $request->input('page', 1);

        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

        $paginatedLines = PaymentLine::with(['method', 'payment.order'])
            ->whereIn('payable_type', [
                \App\Models\OrderMenuRestaurantItem::class,
                \App\Models\RoomService::class,
            ])
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        $monthTotal = $this->fetchTotalByDateRange($monthStart, $monthEnd);

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

            // Détermination du type d'opération (Encaissement ou Recouvrement)
            $regulation = $line->payment_regulation;
            $typeOperation = 'Encaissement';

            if ($regulation) {
                if (!empty($regulation->recouvrement_uuid) || $regulation->recouvrement()->exists()) {
                    $typeOperation = 'Recouvrement';
                }
            }

            return [
                'uuid'           => $line->uuid,
                'code'           => $orderCode,
                'description'    => $description,
                'amount'         => (float) $line->amount,
                'method'         => optional($line->method)->name ?? '',
                'type_operation' => $typeOperation,
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
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

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

    private function fetchCollectionsByDateRange($startDate, $endDate, $createdBy, array $allowedSlugs)
    {
        $query = OtherCashIn::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'regulationMethod:uuid,name',
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->whereNotNull('slug')
            ->whereIn(DB::raw('UPPER(slug)'), $allowedSlugs);

        if ($createdBy) {
            $query->where('created_by', $createdBy);
        }

        return $query->orderByDesc('created_at')
            ->get()
            ->groupBy(function ($item) {
                return strtoupper($item->slug ?? '');
            })
            ->map(function ($items, $slug) {
                $firstItem = $items->first();

                $tree = $this->buildCashInTreeIncome($items);

                if (count($tree) === 1 && strtoupper($tree[0]['name']) === strtoupper($slug)) {
                    $families = $tree[0]['children'];
                } else {
                    $families = $tree;
                }

                return [
                    'expense_type' => null,
                    'title'        => $slug, // Ou 'ENCAISSEMENTS ' . $slug selon votre préférence
                    'total_amount' => (float) $items->sum('amount'),
                    'families'     => $families,
                    'isLoading'    => false,
                ];
            })
            ->values();
    }

    /**
     * Construit l'arbre hiérarchique des encaissements en se basant sur family_hierarchy_uuids
     */
    private function buildCashInTreeIncome($items)
    {
        $groupedByHierarchy = [];

        foreach ($items as $cashIn) {
            $hierarchyUuids = $cashIn->family_hierarchy_uuids ?? [];
            $hierarchyNames = $cashIn->family_hierarchy_names ?? [];

            if (empty($hierarchyUuids) || empty($hierarchyNames)) {
                continue;
            }

            $currentLevel = &$groupedByHierarchy;

            foreach ($hierarchyUuids as $index => $uuid) {
                $name = $hierarchyNames[$index] ?? null;
                if (!$name) {
                    continue;
                }

                if (!isset($currentLevel[$uuid])) {
                    $currentLevel[$uuid] = [
                        'uuid'     => $uuid,
                        'name'     => $name,
                        'amount'   => 0.0,
                        'children' => [],
                        'items'    => [],
                    ];
                }
                if ($index === count($hierarchyUuids) - 1) {
                    $currentLevel[$uuid]['amount'] += (float) $cashIn->amount;
                    $currentLevel[$uuid]['items'][] = [
                        'uuid'           => $cashIn->uuid,
                        'name'           => $cashIn->name,
                        'amount'         => (float) $cashIn->amount,
                        'payment_method' => optional($cashIn->regulationMethod)->name,
                    ];
                } else {
                    $currentLevel[$uuid]['amount'] += (float) $cashIn->amount;
                    $currentLevel = &$currentLevel[$uuid]['children'];
                }
            }
        }

        $formatTree = function (array $nodes) use (&$formatTree) {
            return collect($nodes)->map(function ($node) use ($formatTree) {
                $node['children'] = !empty($node['children']) ? $formatTree($node['children']) : [];
                $node['items'] = array_values($node['items']);
                return $node;
            })->values()->toArray();
        };

        return $formatTree($groupedByHierarchy);
    }
    public function getSalesCategoriesSummary(Request $request)
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

        $orders = OrderMenuRestaurant::with([
            'salesCategory:uuid,name,code',
            'items.menu:uuid,is_generated_from_complement',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
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

        $totalBar = (float) $orders->sum('total_drinks');
        $countBar = (int) $orders->sum(function ($order) {
            return $order->drinks->sum('quantity_exactly');
        });

        return response()->json([
            'success'      => true,
            'date'         => $parsedDate->format('d-m-Y'),
            'data'         => $categoriesCounts,
            'total_global' => $totalFinal,
            'total_bar'    => $totalBar,
            'count_bar'    => $countBar,
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
        $sortedItems = $itemsData->sortBy('created_at')->values();

        return response()->json([
            'success' => true,
            'date_parsed' => $date,
            'rubric_input' => $rubricInput,
            'total_lines_found_for_date' => $itemsData->count(),
            'data'    => $sortedItems
        ]);
    }

    public function getPaidDetailedSalesSummary(Request $request): JsonResponse
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
            'roomService',
            'payment_regulation.recouvrement' // Ajout pour la détection des recouvrements
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

            // Détermination du type d'opération
            $regulation = $line->payment_regulation;
            $typeOperation = 'Encaissement';

            if ($regulation) {
                if (!empty($regulation->recouvrement_uuid) || $regulation->recouvrement()->exists()) {
                    $typeOperation = 'Recouvrement';
                }
            }

            return [
                'uuid'                      => $line->uuid,
                'code'                      => $orderCode,
                'description'               => $description,
                'menu_name'                 => $menuName,
                'sales_category'            => $salesCategoryName,
                'quantity_for_room_service' => $quantity,
                'amount'                    => $amount,
                'method'                    => optional($line->method)->name ?? '',
                'type_operation'            => $typeOperation,
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

    /**
     * Renvoie toutes les commandes et boissons du bar pour une date spécifique.
     * Paramètre attendu dans la requête : ?date=d-m-Y (ex: 22-09-2026)
     */
    public function getBarDetailsByDate(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? \Carbon\Carbon::createFromFormat('d-m-Y', $request->date)
            : \Carbon\Carbon::yesterday();

        $dayStart = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd   = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $orders = OrderMenuRestaurant::with([
            'drinks.drinkConfig.product',
            'drinks'
        ])
            ->where('status', MenuOrderStatus::FACTURATE->value)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->whereHas('drinks')
            ->get();

        $totalCaBar = (float) $orders->sum('total_drinks');

        $formattedOrders = $orders->map(function ($order) {
            return [
                'code' => $order->code,
                'drinks' => $order->drinks->map(function ($drink) {
                    return [
                        'libelle' => $drink->drinkConfig?->product?->name ?? 'Boisson',
                        'status_label' => PaymentOrderItemStatus::safeLabel($drink->regulation_status),
                        'total_price' => $drink->total_price ?? 0,
                    ];
                })
            ];
        });

        return response()->json([
            'success' => true,
            'date_filter' => $parsedDate->format('d/m/Y'),
            'total_chiffre_affaire_bar' => $totalCaBar,
            'total_orders' => $orders->count(),
            'orders' => $formattedOrders,
        ], 200);
    }

    public function getBarPaymentsByDate(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? \Carbon\Carbon::createFromFormat('d-m-Y', $request->date)
            : \Carbon\Carbon::yesterday();

        $dayStart = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd   = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $paymentLines = PaymentLine::with([
            'drink.drinkConfig.product',
            'drink.order',
            'roomService',
            'method',
            'creator',
            'payment_regulation.recouvrement',
            'payment_regulation.payment'
        ])
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->where('slug', RestaurantExpenseSlug::BAR->value)
            ->whereIn('payable_type', [OrderRestaurantDrink::class, RoomService::class])
            ->get();

        $totalGeneral = (float) $paymentLines->sum('amount');

        $formattedPayments = $paymentLines->map(function ($line) {
            $libelle = 'Encaissement Bar';
            if ($line->payable_type === OrderRestaurantDrink::class) {
                $libelle = $line->drink?->drinkConfig?->product?->name ?? $line->drink?->drinkConfig?->drink_name ?? '';
            } elseif ($line->payable_type === RoomService::class) {
                $libelle = $line->roomService?->name ?? 'Room Service';
            }

            $regulation = $line->payment_regulation;
            $typeOperation = 'Encaissement';

            if ($regulation) {
                if (!empty($regulation->recouvrement_uuid) || $regulation->recouvrement()->exists()) {
                    $typeOperation = 'Recouvrement';
                }
            }

            return [
                'code' => $line->drink?->order?->code ?? $line->payment?->code ?? $line->reference ?? '',
                'libelle' => $libelle,
                'montant' => (float) ($line->amount ?? 0),
                'methode' => $line->method?->name ?? 'Espèces',
                'auteur' => $line->creator?->name ?? 'Inconnu',
                'type_operation' => $typeOperation,
                'created_at' => $line->created_at?->format('H:i'),
            ];
        })->sortBy('code')->values();

        return response()->json([
            'success' => true,
            'date_filter' => $parsedDate->format('d/m/Y'),
            'total_encaissements_bar' => $totalGeneral,
            'total_lines' => $paymentLines->count(),
            'payments' => $formattedPayments,
        ], 200);
    }
    
    /**
     * Récupère les encaissements du restaurant groupés par catégorie (Déjeuner, Dîner, Room Service, etc.)
     * pour le jour, le mois et l'année.
     */
    public function getRestaurantCashReceiptsGroupedByCategory(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();

        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $monthEnd   = $dayEnd;

        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();
        $yearEnd    = $dayEnd;

        return response()->json([
            'status' => 'success',
            'jour'   => $this->calculateGroupedReceiptsForPeriod($dayStart, $dayEnd),
            'mois'   => $this->calculateGroupedReceiptsForPeriod($monthStart, $monthEnd),
            'annee'  => $this->calculateGroupedReceiptsForPeriod($yearStart, $yearEnd),
        ]);
    }

    /**
     * Calcule et groupe les montants encaissés par catégorie pour une période donnée.
     */
    private function calculateGroupedReceiptsForPeriod(string $startDate, string $endDate): array
    {
        $paymentLines = PaymentLine::with([
            'item.order.salesCategory',
            'item.menu:uuid,is_generated_from_complement',
            'roomService'
        ])
            ->whereIn('payable_type', [
                OrderMenuRestaurantItem::class,
                RoomService::class,
            ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $categoriesTotals = [];

        $itemLines = $paymentLines->filter(function ($line) {
            return $line->payable_type === OrderMenuRestaurantItem::class && $line->item && $line->item->order;
        });

        $groupedBySalesCategory = $itemLines->groupBy(function ($line) {
            $category = $line->item->order->salesCategory;
            return $category ? strtoupper(trim($category->name)) : 'AUTRES';
        });

        foreach ($groupedBySalesCategory as $name => $linesGroup) {
            $uuid = optional($linesGroup->first()->item->order->salesCategory)->uuid;

            $total = (float) $linesGroup->sum(function ($line) {
                $item = $line->item;
                if ($item && $item->menu && !$item->menu->is_generated_from_complement) {
                    return (float) $line->amount;
                }
                return 0;
            });

            if ($total > 0) {
                $categoriesTotals[$name] = [
                    'uuid'   => $uuid,
                    'amount' => $total
                ];
            }
        }

        $totalAmountRoomService = (float) $paymentLines->filter(function ($line) {
            return $line->payable_type === RoomService::class;
        })->sum('amount');

        $totalAmountDivers = (float) $itemLines->sum(function ($line) {
            $item = $line->item;
            if ($item && $item->menu && (bool) $item->menu->is_generated_from_complement === true) {
                return (float) $line->amount;
            }
            return 0;
        });

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
    /**
     * Récupère un résumé des dépenses par famille avec les montants du jour, du mois et de l'année.
     */
    public function getExpensesSummaryByFamily(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();
        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();

        $allowedSlugs = [RestaurantExpenseSlug::RESTO->value];
        $createdBy    = $request->input('created_by', null);

        $datasets = [
            'jour'  => $this->fetchExpensesByDateRange($dayStart, $dayEnd, $createdBy, $allowedSlugs),
            'mois'  => $this->fetchExpensesByDateRange($monthStart, $dayEnd, $createdBy, $allowedSlugs),
            'annee' => $this->fetchExpensesByDateRange($yearStart, $dayEnd, $createdBy, $allowedSlugs),
        ];

        $familiesSummary = [];

        $mergeTreeNodes = function (array $sourceNodes, string $periodKey, array &$targetRef, bool $isFamilyLevel = true) use (&$mergeTreeNodes) {
            foreach ($sourceNodes as $node) {
                $uuid = $node['uuid'] ?? ('name_' . md5($node['name'] ?? 'unknown'));

                if (!isset($targetRef[$uuid])) {
                    $targetRef[$uuid] = [
                        'uuid'     => $node['uuid'] ?? null,
                        'name'     => $node['name'] ?? 'Autres',
                        'jour'     => 0.0,
                        'mois'     => 0.0,
                        'annee'    => 0.0,
                        'children' => [],
                        'items'    => []
                    ];
                }

                // Pour les familles/enfants, on cumule selon la période en cours
                if ($isFamilyLevel || $periodKey === 'jour') {
                    $targetRef[$uuid][$periodKey] += (float) ($node['amount'] ?? 0);
                }

                if (!empty($node['children'])) {
                    $targetRef[$uuid]['children_indexed'] ??= [];
                    $mergeTreeNodes($node['children'], $periodKey, $targetRef[$uuid]['children_indexed'], false);
                }

                if (!empty($node['items'])) {
                    $targetRef[$uuid]['items_indexed'] ??= [];
                    foreach ($node['items'] as $item) {
                        $itemUuid = $item['uuid'] ?? ('item_' . md5($item['name'] ?? 'unknown'));

                        $paymentMethodName = $item['payment_method']
                            ?? optional($item['method'] ?? null)->name
                            ?? null;

                        $targetRef[$uuid]['items_indexed'][$itemUuid] ??= [
                            'uuid'           => $item['uuid'] ?? null,
                            'name'           => $item['name'] ?? 'Article',
                            'jour'           => 0.0,
                            'mois'           => 0.0,
                            'annee'          => 0.0,
                            'payment_method' => $paymentMethodName,
                        ];

                        $targetRef[$uuid]['items_indexed'][$itemUuid][$periodKey] += (float) ($item['amount'] ?? 0);
                    }
                }
            }
        };

        foreach ($datasets as $periodKey => $datasetItems) {
            foreach ($datasetItems as $group) {
                if (!empty($group['families'])) {
                    $mergeTreeNodes($group['families'], $periodKey, $familiesSummary, true);
                }
            }
        }

        $cleanTreeOutput = function (array $nodes) use (&$cleanTreeOutput) {
            return collect($nodes)->map(function ($node) use ($cleanTreeOutput) {
                $node['children'] = isset($node['children_indexed'])
                    ? $cleanTreeOutput($node['children_indexed'])
                    : [];
                unset($node['children_indexed']);

                $items = isset($node['items_indexed'])
                    ? array_values($node['items_indexed'])
                    : [];
                unset($node['items_indexed']);

                $node['items'] = array_values(array_filter($items, function ($item) {
                    return isset($item['jour']) && $item['jour'] > 0;
                }));

                return $node;
            })->values()->toArray();
        };

        return response()->json([
            'status'   => 'success',
            'families' => $cleanTreeOutput($familiesSummary),
        ]);
    }

    public function getOtherExpensesSummaryByFamily(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();
        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();

        $allowedSlugs = [PdgCategory::AUTRES_DEPENSES->value];
        $createdBy    = $request->input('created_by', null);

        $datasets = [
            'jour'  => $this->fetchExpensesByDateRange($dayStart, $dayEnd, $createdBy, $allowedSlugs),
            'mois'  => $this->fetchExpensesByDateRange($monthStart, $dayEnd, $createdBy, $allowedSlugs),
            'annee' => $this->fetchExpensesByDateRange($yearStart, $dayEnd, $createdBy, $allowedSlugs),
        ];

        $familiesSummary = [];

        $mergeTreeNodes = function (array $sourceNodes, string $periodKey, array &$targetRef, bool $isFamilyLevel = true) use (&$mergeTreeNodes) {
            foreach ($sourceNodes as $node) {
                $uuid = $node['uuid'] ?? ('name_' . md5($node['name'] ?? 'unknown'));

                if (!isset($targetRef[$uuid])) {
                    $targetRef[$uuid] = [
                        'uuid'     => $node['uuid'] ?? null,
                        'name'     => $node['name'] ?? 'Autres',
                        'jour'     => 0.0,
                        'mois'     => 0.0,
                        'annee'    => 0.0,
                        'children' => [],
                        'items'    => []
                    ];
                }

                if ($isFamilyLevel || $periodKey === 'jour') {
                    $targetRef[$uuid][$periodKey] += (float) ($node['amount'] ?? 0);
                }

                if (!empty($node['children'])) {
                    $targetRef[$uuid]['children_indexed'] ??= [];
                    $mergeTreeNodes($node['children'], $periodKey, $targetRef[$uuid]['children_indexed'], false);
                }

                if (!empty($node['items'])) {
                    $targetRef[$uuid]['items_indexed'] ??= [];
                    foreach ($node['items'] as $item) {
                        $itemUuid = $item['uuid'] ?? ('item_' . md5($item['name'] ?? 'unknown'));

                        $paymentMethodName = $item['payment_method']
                            ?? optional($item['method'] ?? null)->name
                            ?? null;

                        $targetRef[$uuid]['items_indexed'][$itemUuid] ??= [
                            'uuid'           => $item['uuid'] ?? null,
                            'name'           => $item['name'] ?? 'Article',
                            'jour'           => 0.0,
                            'mois'           => 0.0,
                            'annee'          => 0.0,
                            'payment_method' => $paymentMethodName,
                        ];
                        $targetRef[$uuid]['items_indexed'][$itemUuid][$periodKey] += (float) ($item['amount'] ?? 0);
                    }
                }
            }
        };

        foreach ($datasets as $periodKey => $datasetItems) {
            foreach ($datasetItems as $group) {
                if (!empty($group['families'])) {
                    $mergeTreeNodes($group['families'], $periodKey, $familiesSummary, true);
                }
            }
        }

        $cleanTreeOutput = function (array $nodes) use (&$cleanTreeOutput) {
            return collect($nodes)->map(function ($node) use ($cleanTreeOutput) {
                $node['children'] = isset($node['children_indexed'])
                    ? $cleanTreeOutput($node['children_indexed'])
                    : [];
                unset($node['children_indexed']);

                $items = isset($node['items_indexed'])
                    ? array_values($node['items_indexed'])
                    : [];
                unset($node['items_indexed']);
                $node['items'] = array_values(array_filter($items, function ($item) {
                    return isset($item['jour']) && $item['jour'] > 0;
                }));

                return $node;
            })->values()->toArray();
        };

        return response()->json([
            'status'   => 'success',
            'families' => $cleanTreeOutput($familiesSummary),
        ]);
    }

    /**
     * Récupère et structure les autres dépenses pour le promoteur sur différentes périodes (jour, mois, année).
     */
    public function getOtherExpensesForPromoter(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();
        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();

        $allowedSlugs = [PdgCategory::AUTRES_DEPENSES->value];
        $createdBy    = $request->input('created_by', null);

        $datasets = [
            'jour'  => $this->fetchExpensesByDateRange($dayStart, $dayEnd, $createdBy, $allowedSlugs),
            'mois'  => $this->fetchExpensesByDateRange($monthStart, $dayEnd, $createdBy, $allowedSlugs),
            'annee' => $this->fetchExpensesByDateRange($yearStart, $dayEnd, $createdBy, $allowedSlugs),
        ];

        $familiesSummary = [];

        $mergeTreeNodes = function (array $sourceNodes, string $periodKey, array &$targetRef, bool $isFamilyLevel = true) use (&$mergeTreeNodes) {
            foreach ($sourceNodes as $node) {
                $uuid = $node['uuid'] ?? ('name_' . md5($node['name'] ?? 'unknown'));

                if (!isset($targetRef[$uuid])) {
                    $targetRef[$uuid] = [
                        'uuid'     => $node['uuid'] ?? null,
                        'name'     => $node['name'] ?? 'Autres',
                        'jour'     => 0.0,
                        'mois'     => 0.0,
                        'annee'    => 0.0,
                        'children' => [],
                        'items'    => []
                    ];
                }

                if ($isFamilyLevel || $periodKey === 'jour') {
                    $targetRef[$uuid][$periodKey] += (float) ($node['amount'] ?? 0);
                }

                if (!empty($node['children'])) {
                    $targetRef[$uuid]['children_indexed'] ??= [];
                    $mergeTreeNodes($node['children'], $periodKey, $targetRef[$uuid]['children_indexed'], false);
                }

                if (!empty($node['items'])) {
                    $targetRef[$uuid]['items_indexed'] ??= [];
                    foreach ($node['items'] as $item) {
                        $itemUuid = $item['uuid'] ?? ('item_' . md5($item['name'] ?? 'unknown'));

                        $paymentMethodName = $item['payment_method']
                            ?? optional($item['method'] ?? null)->name
                            ?? null;

                        $targetRef[$uuid]['items_indexed'][$itemUuid] ??= [
                            'uuid'           => $item['uuid'] ?? null,
                            'name'           => $item['name'] ?? 'Article',
                            'jour'           => 0.0,
                            'mois'           => 0.0,
                            'annee'          => 0.0,
                            'payment_method' => $paymentMethodName,
                        ];

                        $targetRef[$uuid]['items_indexed'][$itemUuid][$periodKey] += (float) ($item['amount'] ?? 0);
                    }
                }
            }
        };

        foreach ($datasets as $periodKey => $datasetItems) {
            foreach ($datasetItems as $group) {
                if (!empty($group['families'])) {
                    $mergeTreeNodes($group['families'], $periodKey, $familiesSummary, true);
                }
            }
        }

        $cleanTreeOutput = function (array $nodes) use (&$cleanTreeOutput) {
            return collect($nodes)->map(function ($node) use ($cleanTreeOutput) {
                $node['children'] = isset($node['children_indexed'])
                    ? $cleanTreeOutput($node['children_indexed'])
                    : [];
                unset($node['children_indexed']);

                $items = isset($node['items_indexed'])
                    ? array_values($node['items_indexed'])
                    : [];
                unset($node['items_indexed']);

                $node['items'] = array_values(array_filter($items, function ($item) {
                    return isset($item['jour']) && $item['jour'] > 0;
                }));

                return $node;
            })->values()->toArray();
        };

        return response()->json([
            'status'   => 'success',
            'families' => $cleanTreeOutput($familiesSummary),
        ], 200);
    }

    /**
     * Récupère et structure les autres encaissements pour le promoteur sur différentes périodes (jour, mois, année).
     */
    public function getOtherCollectionsForPromoter(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();
        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();

        $allowedSlugs = [PdgCategory::AUTRES_ENCAISSEMENTS->value];
        $createdBy    = $request->input('created_by', null);

        // Utilisation de la méthode dédiée aux encaissements OtherCashIn
        $datasets = [
            'jour'  => $this->fetchCollectionsByDateRange($dayStart, $dayEnd, $createdBy, $allowedSlugs),
            'mois'  => $this->fetchCollectionsByDateRange($monthStart, $dayEnd, $createdBy, $allowedSlugs),
            'annee' => $this->fetchCollectionsByDateRange($yearStart, $dayEnd, $createdBy, $allowedSlugs),
        ];

        $familiesSummary = [];

        $mergeTreeNodes = function (array $sourceNodes, string $periodKey, array &$targetRef, bool $isFamilyLevel = true) use (&$mergeTreeNodes) {
            foreach ($sourceNodes as $node) {
                $uuid = $node['uuid'] ?? ('name_' . md5($node['name'] ?? 'unknown'));

                if (!isset($targetRef[$uuid])) {
                    $targetRef[$uuid] = [
                        'uuid'     => $node['uuid'] ?? null,
                        'name'     => $node['name'] ?? 'Autres',
                        'jour'     => 0.0,
                        'mois'     => 0.0,
                        'annee'    => 0.0,
                        'children' => [],
                        'items'    => []
                    ];
                }

                if ($isFamilyLevel || $periodKey === 'jour') {
                    $targetRef[$uuid][$periodKey] += (float) ($node['amount'] ?? 0);
                }

                if (!empty($node['children'])) {
                    $targetRef[$uuid]['children_indexed'] ??= [];
                    $mergeTreeNodes($node['children'], $periodKey, $targetRef[$uuid]['children_indexed'], false);
                }

                if (!empty($node['items'])) {
                    $targetRef[$uuid]['items_indexed'] ??= [];
                    foreach ($node['items'] as $item) {
                        $itemUuid = $item['uuid'] ?? ('item_' . md5($item['name'] ?? 'unknown'));

                        $paymentMethodName = $item['payment_method']
                            ?? optional($item['method'] ?? null)->name
                            ?? null;

                        $targetRef[$uuid]['items_indexed'][$itemUuid] ??= [
                            'uuid'           => $item['uuid'] ?? null,
                            'name'           => $item['name'] ?? 'Article',
                            'jour'           => 0.0,
                            'mois'           => 0.0,
                            'annee'          => 0.0,
                            'payment_method' => $paymentMethodName,
                        ];

                        $targetRef[$uuid]['items_indexed'][$itemUuid][$periodKey] += (float) ($item['amount'] ?? 0);
                    }
                }
            }
        };

        foreach ($datasets as $periodKey => $datasetItems) {
            foreach ($datasetItems as $group) {
                if (!empty($group['families'])) {
                    $mergeTreeNodes($group['families'], $periodKey, $familiesSummary, true);
                }
            }
        }

        $cleanTreeOutput = function (array $nodes) use (&$cleanTreeOutput) {
            return collect($nodes)->map(function ($node) use ($cleanTreeOutput) {
                $node['children'] = isset($node['children_indexed'])
                    ? $cleanTreeOutput($node['children_indexed'])
                    : [];
                unset($node['children_indexed']);

                $items = isset($node['items_indexed'])
                    ? array_values($node['items_indexed'])
                    : [];
                unset($node['items_indexed']);

                $node['items'] = array_values(array_filter($items, function ($item) {
                    return isset($item['jour']) && $item['jour'] > 0;
                }));

                return $node;
            })->values()->toArray();
        };

        return response()->json([
            'status'   => 'success',
            'families' => $cleanTreeOutput($familiesSummary),
        ], 200);
    }

    /**
     * Récupère et structure les encaissements du bar pour le promoteur sur différentes périodes (jour, mois, année).
     */
    public function getBarCollectionsForPromoter(Request $request): JsonResponse
    {
        $parsedDate = $request->filled('date')
            ? Carbon::createFromFormat('d-m-Y', $request->date)
            : Carbon::yesterday();

        $dayStart   = $parsedDate->copy()->startOfDay()->toDateTimeString();
        $dayEnd     = $parsedDate->copy()->endOfDay()->toDateTimeString();
        $monthStart = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $yearStart  = $parsedDate->copy()->startOfYear()->toDateTimeString();

        $allowedSlugs = [RestaurantExpenseSlug::BAR->value];
        $createdBy    = $request->input('created_by', null);

        $datasets = [
            'jour'  => $this->fetchExpensesByDateRange($dayStart, $dayEnd, $createdBy, $allowedSlugs),
            'mois'  => $this->fetchExpensesByDateRange($monthStart, $dayEnd, $createdBy, $allowedSlugs),
            'annee' => $this->fetchExpensesByDateRange($yearStart, $dayEnd, $createdBy, $allowedSlugs),
        ];

        $familiesSummary = [];

        $mergeTreeNodes = function (array $sourceNodes, string $periodKey, array &$targetRef, bool $isFamilyLevel = true) use (&$mergeTreeNodes) {
            foreach ($sourceNodes as $node) {
                $uuid = $node['uuid'] ?? ('name_' . md5($node['name'] ?? 'unknown'));

                if (!isset($targetRef[$uuid])) {
                    $targetRef[$uuid] = [
                        'uuid'     => $node['uuid'] ?? null,
                        'name'     => $node['name'] ?? 'Autres',
                        'jour'     => 0.0,
                        'mois'     => 0.0,
                        'annee'    => 0.0,
                        'children' => [],
                        'items'    => []
                    ];
                }

                if ($isFamilyLevel || $periodKey === 'jour') {
                    $targetRef[$uuid][$periodKey] += (float) ($node['amount'] ?? 0);
                }

                if (!empty($node['children'])) {
                    $targetRef[$uuid]['children_indexed'] ??= [];
                    $mergeTreeNodes($node['children'], $periodKey, $targetRef[$uuid]['children_indexed'], false);
                }

                if (!empty($node['items'])) {
                    $targetRef[$uuid]['items_indexed'] ??= [];
                    foreach ($node['items'] as $item) {
                        $itemUuid = $item['uuid'] ?? ('item_' . md5($item['name'] ?? 'unknown'));

                        $paymentMethodName = $item['payment_method']
                            ?? optional($item['method'] ?? null)->name
                            ?? null;

                        $targetRef[$uuid]['items_indexed'][$itemUuid] ??= [
                            'uuid'           => $item['uuid'] ?? null,
                            'name'           => $item['name'] ?? 'Article',
                            'jour'           => 0.0,
                            'mois'           => 0.0,
                            'annee'          => 0.0,
                            'payment_method' => $paymentMethodName,
                        ];

                        $targetRef[$uuid]['items_indexed'][$itemUuid][$periodKey] += (float) ($item['amount'] ?? 0);
                    }
                }
            }
        };

        foreach ($datasets as $periodKey => $datasetItems) {
            foreach ($datasetItems as $group) {
                if (!empty($group['families'])) {
                    $mergeTreeNodes($group['families'], $periodKey, $familiesSummary, true);
                }
            }
        }

        $cleanTreeOutput = function (array $nodes) use (&$cleanTreeOutput) {
            return collect($nodes)->map(function ($node) use ($cleanTreeOutput) {
                $node['children'] = isset($node['children_indexed'])
                    ? $cleanTreeOutput($node['children_indexed'])
                    : [];
                unset($node['children_indexed']);

                $items = isset($node['items_indexed'])
                    ? array_values($node['items_indexed'])
                    : [];
                unset($node['items_indexed']);

                $node['items'] = array_values(array_filter($items, function ($item) {
                    return isset($item['jour']) && $item['jour'] > 0;
                }));

                return $node;
            })->values()->toArray();
        };

        return response()->json([
            'status'   => 'success',
            'families' => $cleanTreeOutput($familiesSummary),
        ], 200);
    }

}

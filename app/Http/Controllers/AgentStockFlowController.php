<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\StockPerformanceCriterion;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AgentStockFlowController extends Controller
{
    public function index(Request $request)
    {
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay();
        } else {
            $firstOrderDate = OrderMenuRestaurantItem::min('created_at');
            $startDate = $firstOrderDate
                ? Carbon::parse($firstOrderDate)->startOfDay()
                : now()->startOfDay();
        }

        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay();
        } else {
            $endDate = now()->subDay()->endOfDay();
        }

        $agentId = $request->input('agent_id');

        $agentIdsQuery = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
            ->whereNotNull('updated_by');

        if ($agentId) {
            $agentIdsQuery->where('updated_by', $agentId);
        }

        $agentIds = $agentIdsQuery->distinct()->pluck('updated_by');
        $agents = User::whereIn('id', $agentIds)->get();

        $restaurantWarehouse = Warehouse::where('is_used_for_restaurant', true)->first();
        $nomEntrepotMax = $restaurantWarehouse ? $restaurantWarehouse->name : 'N/A';
        $restaurantWarehouseUuid = $restaurantWarehouse ? $restaurantWarehouse->uuid : null;

        $report = [];
        $globalNomProduitMax = 'N/A';
        $globalPlafondMax = 0;
        $stocksCuisine = [];

        if ($restaurantWarehouseUuid) {
            $stocksCuisine = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $restaurantWarehouseUuid)
                ->pluck('quantity', 'produit_uuid')
                ->toArray();

            $topProductPointGlobal = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $restaurantWarehouseUuid)
                ->orderBy('quantity', 'desc')
                ->first();

            if ($topProductPointGlobal) {
                $globalNomProduitMax = $topProductPointGlobal->product ? $topProductPointGlobal->product->name : 'N/A';
                $globalPlafondMax = (float) $topProductPointGlobal->quantity;
            }
        }

        if ($globalPlafondMax <= 0) {
            $globalPlafondMax = 1;
        }

        // Variables globales pour cumuler les données et la formule de tous les agents
        $sumTotalPlatsVendus = 0;
        $sumTotalPointsReels = 0;
        $sumTotalPointsMax = 0;
        $globalNumeratorParts = [];
        $globalDenominatorParts = [];

        foreach ($agents as $agent) {
            $listeVentes = OrderMenuRestaurantItem::with(['menu.compositionItems.product', 'menu', 'virtuals'])
                ->where('updated_by', $agent->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                ->get();

            if ($listeVentes->isEmpty()) {
                $report[] = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                    'plats_vendus' => 0,
                    'points_reels' => 0,
                    'points_max' => 0,
                    'taux_ecoulement' => 'N/A',
                    'commande' => 'Aucune vente',
                    'details_calcul' => null,
                    'statut' => 'Bar uniquement: Pas de vente de restauration sur ce service',
                    'badge_color' => 'secondary'
                ];
                continue;
            }

            $pointsReelsAgent = 0;
            $pointsMaximumPossibles = 0;
            $totalPlatsVendus = (int) $listeVentes->count();
            $compositionDetails = [];
            $commandesNoms = [];
            $stockReferenceArticles = [];
            $strNumeratorParts = [];
            $strDenominatorParts = [];

            foreach ($listeVentes as $vente) {
                $quantiteVendue = (float) $vente->quantity_exactly;
                $menu = $vente->menu;
                if ($menu) {
                    $commandesNoms[] = $quantiteVendue . ' ' . ($menu->name ?? 'plat');
                }

                if (!$menu || $menu->compositionItems->isEmpty()) {
                    continue;
                }

                foreach ($menu->compositionItems as $item) {
                    $qteDansRecette = (float) $item->quantity_used;
                    $productUuid = $item->product_uuid;
                    $nomProduit = $item->product ? $item->product->name : '';

                    $matchingVirtual = $vente->virtuals->where('product_uuid', $productUuid)->first();
                    $stockInitialArt = ($matchingVirtual && isset($matchingVirtual->stock_at_billing))
                        ? (float) $matchingVirtual->stock_at_billing
                        : (float) ($stocksCuisine[$productUuid] ?? 0);

                    $plafondArticle = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing))
                        ? (float) $matchingVirtual->plafond_at_billing
                        : $globalPlafondMax;

                    $stockReferenceArticles[strtolower($nomProduit)] = $stockInitialArt;

                    $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                    $pointsReelsAgent += $calculPointArt;

                    $calculPointMax = $quantiteVendue * $qteDansRecette * $plafondArticle;
                    $pointsMaximumPossibles += $calculPointMax;

                    // Morceaux pour l'agent
                    $partNum = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                    $partDen = "({$quantiteVendue} * {$qteDansRecette} * {$plafondArticle})";

                    $strNumeratorParts[] = $partNum;
                    $strDenominatorParts[] = $partDen;

                    // Morceaux cumulés pour la formule globale de tous les agents
                    $globalNumeratorParts[] = $partNum;
                    $globalDenominatorParts[] = $partDen;

                    $compositionDetails[] = [
                        'ingredient' => strtolower($nomProduit),
                        'quantite_vendue' => $quantiteVendue,
                        'quantite_recette' => $qteDansRecette,
                        'stock_article' => $stockInitialArt,
                        'plafond_reference' => $plafondArticle,
                        'calcul_point' => round($calculPointArt, 2)
                    ];
                }
            }

            $tauxEcoulementGlobal = $pointsMaximumPossibles > 0
                ? round(($pointsReelsAgent / $pointsMaximumPossibles) * 100, 2)
                : 0;

            $formuleString = "[(" . implode(' + ', $strNumeratorParts) . ") / (" . implode(' + ', $strDenominatorParts) . ")] * 100%";

            $evaluation = $this->evaluatePerformance($tauxEcoulementGlobal);

            // Cumul pour la moyenne générale
            $sumTotalPlatsVendus += $totalPlatsVendus;
            $sumTotalPointsReels += $pointsReelsAgent;
            $sumTotalPointsMax += $pointsMaximumPossibles;

            $report[] = [
                'agent_id' => $agent->id,
                'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                'plats_vendus' => $totalPlatsVendus,
                'points_reels' => round($pointsReelsAgent, 2),
                'points_max' => round($pointsMaximumPossibles, 2),
                'taux_ecoulement' => $tauxEcoulementGlobal . '%',
                'commande' => implode(', ', $commandesNoms),
                'stock_reference_articles' => $stockReferenceArticles,
                'details_calcul' => [
                    'composition_plat' => $compositionDetails,
                    'points_reels' => round($pointsReelsAgent, 2),
                    'points_max' => round($pointsMaximumPossibles, 2),
                    'formule_appliquee' => $formuleString,
                    'taux_ecoulement' => $tauxEcoulementGlobal . '%'
                ],
                'statut' => $evaluation['message'],
                'badge_color' => $evaluation['badge_color']
            ];
        }

        // Calcul de la ligne globale / moyenne générale de tous les agents
        $globalTauxEcoulement = $sumTotalPointsMax > 0
            ? round(($sumTotalPointsReels / $sumTotalPointsMax) * 100, 2)
            : 0;
        $globalEvaluation = $this->evaluatePerformance($globalTauxEcoulement);

        // Construction de la formule globale combinant toutes les transactions de tous les agents
        $globalFormuleString = !empty($globalNumeratorParts)
            ? "[(" . implode(' + ', $globalNumeratorParts) . ") / (" . implode(' + ', $globalDenominatorParts) . ")] * 100%"
            : "N/A";

        $generalAverageSummary = [
            'total_plats_vendus' => $sumTotalPlatsVendus,
            'total_points_reels' => round($sumTotalPointsReels, 2),
            'total_points_max' => round($sumTotalPointsMax, 2),
            'taux_ecoulement_global' => $globalTauxEcoulement . '%',
            'formule_appliquee' => $globalFormuleString, // Ajout de la formule globale
            'statut' => $globalEvaluation['message'],
            'badge_color' => $globalEvaluation['badge_color']
        ];

        return response()->json([
            'success' => true,
            'message' => 'Rapport détaillé du taux d\'écoulement basé sur l\'historique des transactions.',
            'info_stock_global' => [
                'entrepot_cuisine' => $nomEntrepotMax,
                'produit_le_plus_eleve' => $globalNomProduitMax,
                'plafond_valeur_max' => $globalPlafondMax
            ],
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'filtered_by_agent_id' => $agentId ?? 'Tous les agents',
                'agents_performance' => $report,
                'general_average' => $generalAverageSummary
            ]
        ]);
    }
    public function indexBarDistribution(Request $request)
    {
        // 1. Gestion de la date de début (Début de l'app si non renseigné)
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay();
        } else {
            $firstOrderDate = OrderRestaurantDrink::min('created_at');
            $startDate = $firstOrderDate
                ? Carbon::parse($firstOrderDate)->startOfDay()
                : now()->startOfDay();
        }

        // 2. Gestion de la date de fin (Fin de la journée d'hier / journée n-1 si non renseigné)
        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay();
        } else {
            $endDate = now()->subDay()->endOfDay();
        }

        $agentId = $request->input('agent_id');
        $today = now()->toDateString();

        $warehouse = Warehouse::where('is_bar_warehouse', true)->first();
        $warehouseUuid = $warehouse?->uuid;

        $globalPlafondMax = 1;
        $globalNomProduitMax = 'N/A';
        $snapshotsToday = [];

        if ($warehouseUuid) {
            $snapshotsToday = \App\Models\StockSnapshot::where('snapshot_date', $today)
                ->pluck('initial_quantity', 'product_uuid')
                ->toArray();

            $topDist = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $warehouseUuid)
                ->orderBy('quantity', 'desc')
                ->first();

            if ($topDist) {
                $globalNomProduitMax = $topDist->product?->name ?? 'N/A';
                $globalProductUuid = $topDist->produit_uuid ?? $topDist->product_uuid;

                $valeurPlafond = isset($snapshotsToday[$globalProductUuid])
                    ? (float) $snapshotsToday[$globalProductUuid]
                    : (float) $topDist->quantity;

                $globalPlafondMax = $valeurPlafond > 0 ? $valeurPlafond : 1;
            }
        }

        $agentIdsQuery = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
            ->whereHas('drinkConfig', fn($q) => $q->where('is_finished_product', true))
            ->whereNotNull('updated_by');

        if ($agentId) {
            $agentIdsQuery->where('updated_by', $agentId);
        }

        $agentIds = $agentIdsQuery->distinct()->pluck('updated_by');
        if ($agentIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Rapport du taux d\'écoulement - Bar Distribution.',
                'info_stock_global' => [
                    'entrepot' => $warehouse?->name ?? 'N/A',
                    'produit_le_plus_eleve' => $globalNomProduitMax,
                    'plafond_valeur_max' => $globalPlafondMax
                ],
                'data' => [
                    'start_date' => $startDate->format('d-m-Y'),
                    'end_date' => $endDate->format('d-m-Y'),
                    'agents_performance' => [],
                    'general_average' => null
                ]
            ]);
        }

        $agents = User::whereIn('id', $agentIds)->get();

        $salesByAgent = OrderRestaurantDrink::with(['drinkConfig.product', 'drinkConfig', 'order', 'virtuals'])
            ->whereIn('updated_by', $agentIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
            ->whereHas('drinkConfig', fn($q) => $q->where('is_finished_product', true))
            ->get()
            ->groupBy('updated_by');

        $allProductUuids = [];
        foreach ($salesByAgent as $sales) {
            foreach ($sales as $vente) {
                $drinkConfig = $vente->drinkConfig;
                if ($drinkConfig) {
                    $allProductUuids[] = $drinkConfig->product_uuid ?? $drinkConfig->uuid;
                }
            }
        }

        $stockPoints = [];
        if ($warehouseUuid && !empty($allProductUuids)) {
            $stockPoints = \App\Models\ProductPoint::where('point_uuid', $warehouseUuid)
                ->whereIn('produit_uuid', array_unique($allProductUuids))
                ->pluck('quantity', 'produit_uuid')
                ->toArray();
        }

        $report = [];

        // Variables pour le calcul de la moyenne générale
        $sumTotalBoissonsVendues = 0;
        $sumTotalPointsReels = 0;
        $sumTotalPointsMax = 0;
        $globalNumeratorParts = [];
        $globalDenominatorParts = [];

        foreach ($agents as $agent) {
            $listeVentes = $salesByAgent[$agent->id] ?? collect();
            if ($listeVentes->isEmpty()) continue;

            $pointsReelsAgent = 0;
            $pointsMaximumPossibles = 0;
            $totalBoissonsVendues = $listeVentes->count();
            $compositionDetails = [];
            $commandesNoms = [];
            $stockReferenceArticles = [];
            $strNumeratorParts = [];
            $strDenominatorParts = [];

            foreach ($listeVentes as $vente) {
                $quantiteVendue = (float) ($vente->quantity_exactly ?? $vente->quantity);
                $drinkConfig = $vente->drinkConfig;
                $codeCommande = $vente->order?->code ?? $vente->order?->uuid ?? 'N/A';

                if (!$drinkConfig) continue;

                $nomProduit = $drinkConfig->display_name;
                $commandesNoms[] = $quantiteVendue . ' ' . $nomProduit . " (Cmd: {$codeCommande})";

                $productUuid = $drinkConfig->product_uuid ?? $drinkConfig->uuid;
                $qteDansRecette = 1;

                $matchingVirtual = $vente->virtuals->where('product_uuid', $productUuid)->first();

                if ($matchingVirtual && isset($matchingVirtual->stock_at_billing)) {
                    $stockInitialArt = (float) $matchingVirtual->stock_at_billing;
                } elseif (isset($snapshotsToday[$productUuid])) {
                    $stockInitialArt = (float) $snapshotsToday[$productUuid];
                } else {
                    $stockInitialArt = (float) ($stockPoints[$productUuid] ?? ($stockPoints[$drinkConfig->uuid] ?? 0));
                }

                $plafondArticle = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing))
                    ? (float) $matchingVirtual->plafond_at_billing
                    : $globalPlafondMax;

                $stockReferenceArticles[strtolower($nomProduit)] = $stockInitialArt;

                // --- CALCULS CORRIGÉS AVEC DES OPÉRATEURS DE MULTIPLICATION VALIDES (*) ---
                $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                $pointsReelsAgent += $calculPointArt;

                $calculPointMax = $quantiteVendue * $qteDansRecette * $plafondArticle;
                $pointsMaximumPossibles += $calculPointMax;

                $partNum = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                $partDen = "({$quantiteVendue} * {$qteDansRecette} * {$plafondArticle})";

                $strNumeratorParts[] = $partNum;
                $strDenominatorParts[] = $partDen;

                // Cumul global
                $globalNumeratorParts[] = $partNum;
                $globalDenominatorParts[] = $partDen;

                $compositionDetails[] = [
                    'boisson' => strtolower($nomProduit),
                    'code_commande' => $codeCommande,
                    'quantite_vendue' => $quantiteVendue,
                    'stock_article' => $stockInitialArt,
                    'plafond_reference' => $plafondArticle,
                    'calcul_point' => round($calculPointArt, 2)
                ];
            }

            $tauxEcoulementGlobal = $pointsMaximumPossibles > 0
                ? round(($pointsReelsAgent / $pointsMaximumPossibles) * 100, 2)
                : 0;

            $formuleString = "[(" . implode(' + ', $strNumeratorParts) . ") / (" . implode(' + ', $strDenominatorParts) . ")] * 100%";
            $evaluation = $this->evaluatePerformance($tauxEcoulementGlobal);

            // Cumul pour les totaux généraux
            $sumTotalBoissonsVendues += $totalBoissonsVendues;
            $sumTotalPointsReels += $pointsReelsAgent;
            $sumTotalPointsMax += $pointsMaximumPossibles;

            $report[] = [
                'agent_id' => $agent->id,
                'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                'boissons_vendues' => $totalBoissonsVendues,
                'points_reels' => round($pointsReelsAgent, 2),
                'points_max' => round($pointsMaximumPossibles, 2),
                'taux_ecoulement' => $tauxEcoulementGlobal . '%',
                'commande' => implode(', ', $commandesNoms),
                'stock_reference_articles' => $stockReferenceArticles,
                'details_calcul' => [
                    'composition_boisson' => $compositionDetails,
                    'points_reels' => round($pointsReelsAgent, 2),
                    'points_max' => round($pointsMaximumPossibles, 2),
                    'formule_appliquee' => $formuleString,
                    'taux_ecoulement' => $tauxEcoulementGlobal . '%'
                ],
                'statut' => $evaluation['message'],
                'badge_color' => $evaluation['badge_color']
            ];
        }

        // Calcul de la ligne globale / moyenne générale de tous les agents
        $globalTauxEcoulement = $sumTotalPointsMax > 0
            ? round(($sumTotalPointsReels / $sumTotalPointsMax) * 100, 2)
            : 0;
        $globalEvaluation = $this->evaluatePerformance($globalTauxEcoulement);

        $globalFormuleString = !empty($globalNumeratorParts)
            ? "[(" . implode(' + ', $globalNumeratorParts) . ") / (" . implode(' + ', $globalDenominatorParts) . ")] * 100%"
            : "N/A";

        $generalAverageSummary = [
            'total_boissons_vendues' => $sumTotalBoissonsVendues,
            'total_points_reels' => round($sumTotalPointsReels, 2),
            'total_points_max' => round($sumTotalPointsMax, 2),
            'taux_ecoulement_global' => $globalTauxEcoulement . '%',
            'formule_appliquee' => $globalFormuleString,
            'statut' => $globalEvaluation['message'],
            'badge_color' => $globalEvaluation['badge_color']
        ];

        return response()->json([
            'success' => true,
            'message' => 'Rapport du taux d\'écoulement - Bar Distribution.',
            'info_stock_global' => [
                'entrepot' => $warehouse?->name ?? 'N/A',
                'produit_le_plus_eleve' => $globalNomProduitMax,
                'plafond_valeur_max' => $globalPlafondMax
            ],
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'agents_performance' => $report,
                'general_average' => $generalAverageSummary
            ]
        ]);
    }

    public function indexBarTransformation(Request $request)
    {
        // 1. Gestion de la date de début (Début de l'app si non renseigné)
        if ($request->filled('start_date')) {
            $startDate = Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay();
        } else {
            $firstOrderDate = OrderRestaurantDrink::min('created_at');
            $startDate = $firstOrderDate
                ? Carbon::parse($firstOrderDate)->startOfDay()
                : now()->startOfDay();
        }

        // 2. Gestion de la date de fin (Fin de la journée d'hier / journée n-1 si non renseigné)
        if ($request->filled('end_date')) {
            $endDate = Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay();
        } else {
            $endDate = now()->subDay()->endOfDay();
        }

        $agentId = $request->input('agent_id');
        $today = now()->toDateString();

        $warehouse = Warehouse::where('is_used_for_drinks_transformation', true)->first();
        $warehouseUuid = $warehouse?->uuid;

        $globalPlafondMax = 1;
        $globalNomProduitMax = 'N/A';
        $snapshotsToday = [];

        if ($warehouseUuid) {
            $snapshotsToday = \App\Models\StockSnapshot::where('snapshot_date', $today)
                ->pluck('initial_quantity', 'product_uuid')
                ->toArray();

            $topTrans = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $warehouseUuid)
                ->orderBy('quantity', 'desc')
                ->first();

            if ($topTrans) {
                $globalNomProduitMax = $topTrans->product?->name ?? 'N/A';
                $globalProductUuid = $topTrans->produit_uuid ?? $topTrans->product_uuid;

                $valeurPlafond = isset($snapshotsToday[$globalProductUuid])
                    ? (float) $snapshotsToday[$globalProductUuid]
                    : (float) $topTrans->quantity;

                $globalPlafondMax = max($valeurPlafond, 1);
            }
        }

        $agentIdsQuery = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
            ->whereHas('drinkConfig', fn($q) => $q->where('is_transformable_product', true))
            ->whereNotNull('updated_by');

        if ($agentId) {
            $agentIdsQuery->where('updated_by', $agentId);
        }

        $agentIds = $agentIdsQuery->distinct()->pluck('updated_by');
        if ($agentIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Rapport du taux d\'écoulement - Bar Transformation.',
                'info_stock_global' => [
                    'entrepot' => $warehouse?->name ?? 'N/A',
                    'produit_le_plus_eleve' => $globalNomProduitMax,
                    'plafond_valeur_max' => $globalPlafondMax
                ],
                'data' => [
                    'start_date' => $startDate->format('d-m-Y'),
                    'end_date' => $endDate->format('d-m-Y'),
                    'agents_performance' => [],
                    'general_average' => null
                ]
            ]);
        }

        $agents = User::whereIn('id', $agentIds)->get();

        $salesByAgent = OrderRestaurantDrink::with([
            'drinkConfig.product',
            'drinkConfig.composition.items.product',
            'order',
            'virtuals'
        ])
            ->whereIn('updated_by', $agentIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
            ->whereHas('drinkConfig', fn($q) => $q->where('is_transformable_product', true))
            ->get()
            ->groupBy('updated_by');

        $allProductUuids = [];
        foreach ($salesByAgent as $sales) {
            foreach ($sales as $vente) {
                $drinkConfig = $vente->drinkConfig;
                if (!$drinkConfig) continue;

                $items = $drinkConfig->composition?->items;
                if ($items && $items->isNotEmpty()) {
                    foreach ($items as $item) {
                        if ($item->product_uuid) $allProductUuids[] = $item->product_uuid;
                    }
                } else {
                    $allProductUuids[] = $drinkConfig->product_uuid ?? $drinkConfig->uuid;
                }
            }
        }

        $stockPoints = [];
        if ($warehouseUuid && !empty($allProductUuids)) {
            $stockPoints = \App\Models\ProductPoint::where('point_uuid', $warehouseUuid)
                ->whereIn('produit_uuid', array_unique($allProductUuids))
                ->pluck('quantity', 'produit_uuid')
                ->toArray();
        }

        $report = [];

        // Variables globales pour le calcul de la moyenne générale
        $sumTotalBoissonsVendues = 0;
        $sumTotalPointsReels = 0;
        $sumTotalPointsMax = 0;
        $globalNumeratorParts = [];
        $globalDenominatorParts = [];

        foreach ($agents as $agent) {
            $listeVentes = $salesByAgent[$agent->id] ?? collect();
            if ($listeVentes->isEmpty()) continue;

            $pointsReelsAgent = 0;
            $pointsMaximumPossibles = 0;
            $totalBoissonsVendues = $listeVentes->count();
            $compositionDetails = [];
            $commandesNoms = [];
            $stockReferenceArticles = [];
            $strNumeratorParts = [];
            $strDenominatorParts = [];

            foreach ($listeVentes as $vente) {
                $quantiteVendue = (float) ($vente->quantity_exactly ?? $vente->quantity);
                $drinkConfig = $vente->drinkConfig;
                $codeCommande = $vente->order?->code ?? $vente->order?->uuid ?? 'N/A';

                if (!$drinkConfig) continue;

                $nomBoisson = $drinkConfig->display_name;
                $commandesNoms[] = $quantiteVendue . ' ' . $nomBoisson . " (Cmd: {$codeCommande})";

                $items = $drinkConfig->composition?->items;

                if (!$items || $items->isEmpty()) {
                    $productUuid = $drinkConfig->product_uuid ?? $drinkConfig->uuid;
                    $qteDansRecette = 1;

                    $matchingVirtual = $vente->virtuals->where('product_uuid', $productUuid)->first();

                    if ($matchingVirtual && isset($matchingVirtual->stock_at_billing)) {
                        $stockInitialArt = (float) $matchingVirtual->stock_at_billing;
                    } elseif (isset($snapshotsToday[$productUuid])) {
                        $stockInitialArt = (float) $snapshotsToday[$productUuid];
                    } else {
                        $stockInitialArt = (float) ($stockPoints[$productUuid] ?? ($stockPoints[$drinkConfig->uuid] ?? 0));
                    }

                    $plafondArticle = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing))
                        ? (float) $matchingVirtual->plafond_at_billing
                        : $globalPlafondMax;

                    $stockReferenceArticles[strtolower($nomBoisson)] = $stockInitialArt;

                    $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                    $pointsReelsAgent += $calculPointArt;

                    $calculPointMax = $quantiteVendue * $qteDansRecette * $plafondArticle;
                    $pointsMaximumPossibles += $calculPointMax;

                    $partNum = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                    $partDen = "({$quantiteVendue} * {$qteDansRecette} * {$plafondArticle})";

                    $strNumeratorParts[] = $partNum;
                    $strDenominatorParts[] = $partDen;

                    $globalNumeratorParts[] = $partNum;
                    $globalDenominatorParts[] = $partDen;

                    $compositionDetails[] = [
                        'boisson' => strtolower($nomBoisson),
                        'code_commande' => $codeCommande,
                        'quantite_vendue' => $quantiteVendue,
                        'stock_article' => $stockInitialArt,
                        'plafond_reference' => $plafondArticle,
                        'calcul_point' => round($calculPointArt, 2)
                    ];
                } else {
                    foreach ($items as $item) {
                        $qteDansRecette = (float) $item->quantity_used;
                        $productUuid = $item->product_uuid;
                        $nomIngredient = $item->product?->name ?? 'Ingrédient';

                        $matchingVirtual = $vente->virtuals->where('product_uuid', $productUuid)->first();

                        if ($matchingVirtual && isset($matchingVirtual->stock_at_billing)) {
                            $stockInitialArt = (float) $matchingVirtual->stock_at_billing;
                        } elseif (isset($snapshotsToday[$productUuid])) {
                            $stockInitialArt = (float) $snapshotsToday[$productUuid];
                        } else {
                            $stockInitialArt = (float) ($stockPoints[$productUuid] ?? 0);
                        }

                        $plafondArticle = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing))
                            ? (float) $matchingVirtual->plafond_at_billing
                            : $globalPlafondMax;

                        $stockReferenceArticles[strtolower($nomIngredient)] = $stockInitialArt;

                        $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                        $pointsReelsAgent += $calculPointArt;

                        $calculPointMax = $quantiteVendue * $qteDansRecette * $plafondArticle;
                        $pointsMaximumPossibles += $calculPointMax;

                        $partNum = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                        $partDen = "({$quantiteVendue} * {$qteDansRecette} * {$plafondArticle})";

                        $strNumeratorParts[] = $partNum;
                        $strDenominatorParts[] = $partDen;

                        $globalNumeratorParts[] = $partNum;
                        $globalDenominatorParts[] = $partDen;

                        $compositionDetails[] = [
                            'boisson' => strtolower($nomBoisson),
                            'ingredient' => strtolower($nomIngredient),
                            'code_commande' => $codeCommande,
                            'quantite_vendue' => $quantiteVendue,
                            'quantite_recette' => $qteDansRecette,
                            'stock_article' => $stockInitialArt,
                            'plafond_reference' => $plafondArticle,
                            'calcul_point' => round($calculPointArt, 2)
                        ];
                    }
                }
            }

            $tauxEcoulementGlobal = $pointsMaximumPossibles > 0
                ? round(($pointsReelsAgent / $pointsMaximumPossibles) * 100, 2)
                : 0;

            $formuleString = "[(" . implode(' + ', $strNumeratorParts) . ") / (" . implode(' + ', $strDenominatorParts) . ")] * 100%";
            $evaluation = $this->evaluatePerformance($tauxEcoulementGlobal);

            // Cumul des totaux généraux
            $sumTotalBoissonsVendues += $totalBoissonsVendues;
            $sumTotalPointsReels += $pointsReelsAgent;
            $sumTotalPointsMax += $pointsMaximumPossibles;

            $report[] = [
                'agent_id' => $agent->id,
                'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                'boissons_vendues' => $totalBoissonsVendues,
                'points_reels' => round($pointsReelsAgent, 2),
                'points_max' => round($pointsMaximumPossibles, 2),
                'taux_ecoulement' => $tauxEcoulementGlobal . '%',
                'commande' => implode(', ', $commandesNoms),
                'stock_reference_articles' => $stockReferenceArticles,
                'details_calcul' => [
                    'composition_boisson' => $compositionDetails,
                    'points_reels' => round($pointsReelsAgent, 2),
                    'points_max' => round($pointsMaximumPossibles, 2),
                    'formule_appliquee' => $formuleString,
                    'taux_ecoulement' => $tauxEcoulementGlobal . '%'
                ],
                'statut' => $evaluation['message'],
                'badge_color' => $evaluation['badge_color']
            ];
        }

        // Calcul de la moyenne générale / total global pour tous les agents
        $globalTauxEcoulement = $sumTotalPointsMax > 0
            ? round(($sumTotalPointsReels / $sumTotalPointsMax) * 100, 2)
            : 0;
        $globalEvaluation = $this->evaluatePerformance($globalTauxEcoulement);

        $globalFormuleString = !empty($globalNumeratorParts)
            ? "[(" . implode(' + ', $globalNumeratorParts) . ") / (" . implode(' + ', $globalDenominatorParts) . ")] * 100%"
            : "N/A";

        $generalAverageSummary = [
            'total_boissons_vendues' => $sumTotalBoissonsVendues,
            'total_points_reels' => round($sumTotalPointsReels, 2),
            'total_points_max' => round($sumTotalPointsMax, 2),
            'taux_ecoulement_global' => $globalTauxEcoulement . '%',
            'formule_appliquee' => $globalFormuleString,
            'statut' => $globalEvaluation['message'],
            'badge_color' => $globalEvaluation['badge_color']
        ];

        return response()->json([
            'success' => true,
            'message' => 'Rapport du taux d\'écoulement - Bar Transformation.',
            'info_stock_global' => [
                'entrepot' => $warehouse?->name ?? 'N/A',
                'produit_le_plus_eleve' => $globalNomProduitMax,
                'plafond_valeur_max' => $globalPlafondMax
            ],
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'agents_performance' => $report,
                'general_average' => $generalAverageSummary
            ]
        ]);
    }

    private function evaluatePerformance(float $taux): array
    {
        $criterion = StockPerformanceCriterion::where('is_active', true)
            ->where('min_rate', '<=', $taux)
            ->where('max_rate', '>=', $taux)
            ->first()
            ?? StockPerformanceCriterion::where('is_active', true)
                ->orderByRaw('ABS(min_rate - ?)', [$taux])
                ->first();

        if (!$criterion) {
            return [
                'message' => "Performance non évaluée pour un taux de {$taux}%.",
                'badge_color' => 'danger'
            ];
        }

        return [
            'message' => $criterion->description_template,
            'badge_color' => $criterion->badge_color ?? 'success'
        ];
    }


    public function print(Request $request)
    {
        try {
            // 1. Gestion des dates (Début de l'app jusqu'à hier par défaut)
            if ($request->filled('start_date')) {
                $startDate = Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay();
            } else {
                $firstOrderDate = OrderMenuRestaurantItem::min('created_at');
                $startDate = $firstOrderDate
                    ? Carbon::parse($firstOrderDate)->startOfDay()
                    : now()->startOfDay();
            }

            if ($request->filled('end_date')) {
                $endDate = Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay();
            } else {
                $endDate = now()->subDay()->endOfDay();
            }

            $agentId = $request->input('agent_id');
            $today = now()->toDateString();


            // ==========================================
            // SECTION 1 : CUISINE
            // ==========================================
            $agentIdsQueryKitchen = OrderMenuRestaurantItem::whereBetween('created_at', [$startDate, $endDate])
                ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                ->whereNotNull('updated_by');

            if ($agentId) {
                $agentIdsQueryKitchen->where('updated_by', $agentId);
            }

            $agentIdsKitchen = $agentIdsQueryKitchen->distinct()->pluck('updated_by');
            $agentsKitchen = User::whereIn('id', $agentIdsKitchen)->get();

            $restaurantWarehouse = Warehouse::where('is_used_for_restaurant', true)->first();
            $nomEntrepotMax = $restaurantWarehouse ? $restaurantWarehouse->name : 'N/A';
            $restaurantWarehouseUuid = $restaurantWarehouse ? $restaurantWarehouse->uuid : null;

            $globalPlafondMaxKitchen = 1;
            $globalNomProduitMaxKitchen = 'N/A';
            $stocksCuisine = [];

            if ($restaurantWarehouseUuid) {
                $stocksCuisine = \App\Models\ProductPoint::with('product')
                    ->where('point_uuid', $restaurantWarehouseUuid)
                    ->pluck('quantity', 'produit_uuid')
                    ->toArray();

                $topProductPointGlobal = \App\Models\ProductPoint::with('product')
                    ->where('point_uuid', $restaurantWarehouseUuid)
                    ->orderBy('quantity', 'desc')
                    ->first();

                if ($topProductPointGlobal) {
                    $globalNomProduitMaxKitchen = $topProductPointGlobal->product?->name ?? 'N/A';
                    $globalPlafondMaxKitchen = max((float) $topProductPointGlobal->quantity, 1);
                }
            }

            $reportKitchen = [];
            $sumPlatsVendus = 0; $sumPtsReelsK = 0; $sumPtsMaxK = 0;
            $numPartsK = []; $denPartsK = [];

            foreach ($agentsKitchen as $agent) {
                $listeVentes = OrderMenuRestaurantItem::with(['menu.compositionItems.product', 'menu', 'virtuals'])
                    ->where('updated_by', $agent->id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                    ->get();

                if ($listeVentes->isEmpty()) continue;

                $ptsReelsAgent = 0; $ptsMaxAgent = 0; $totalVentes = $listeVentes->count();
                $strNum = []; $strDen = []; $commandesNoms = [];

                foreach ($listeVentes as $vente) {
                    $qteVendue = (float) $vente->quantity_exactly;
                    $menu = $vente->menu;
                    if ($menu) $commandesNoms[] = $qteVendue . ' ' . ($menu->name ?? 'plat');
                    if (!$menu || $menu->compositionItems->isEmpty()) continue;

                    foreach ($menu->compositionItems as $item) {
                        $qteRecette = (float) $item->quantity_used;
                        $prodUuid = $item->product_uuid;
                        $matchingVirtual = $vente->virtuals->where('product_uuid', $prodUuid)->first();

                        $stockArt = ($matchingVirtual && isset($matchingVirtual->stock_at_billing))
                            ? (float) $matchingVirtual->stock_at_billing
                            : (float) ($stocksCuisine[$prodUuid] ?? 0);

                        $plafondArt = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing))
                            ? (float) $matchingVirtual->plafond_at_billing
                            : $globalPlafondMaxKitchen;

                        $calcArt = $qteVendue * $qteRecette * $stockArt;
                        $calcMax = $qteVendue * $qteRecette * $plafondArt;

                        $ptsReelsAgent += $calcArt;
                        $ptsMaxAgent += $calcMax;

                        $pNum = "({$qteVendue} * {$qteRecette} * {$stockArt})";
                        $pDen = "({$qteVendue} * {$qteRecette} * {$plafondArt})";
                        $strNum[] = $pNum; $strDen[] = $pDen;
                        $numPartsK[] = $pNum; $denPartsK[] = $pDen;
                    }
                }

                $tauxAgent = $ptsMaxAgent > 0 ? round(($ptsReelsAgent / $ptsMaxAgent) * 100, 2) : 0;
                $eval = $this->evaluatePerformance($tauxAgent);

                $sumPlatsVendus += $totalVentes;
                $sumPtsReelsK += $ptsReelsAgent;
                $sumPtsMaxK += $ptsMaxAgent;

                $reportKitchen[] = [
                    'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                    'plats_vendus' => $totalVentes,
                    'points_reels' => round($ptsReelsAgent, 2),
                    'points_max' => round($ptsMaxAgent, 2),
                    'taux_ecoulement' => $tauxAgent . '%',
                    'statut' => $eval['message'],
                    'badge_color' => $eval['badge_color']
                ];
            }

            $globalTauxK = $sumPtsMaxK > 0 ? round(($sumPtsReelsK / $sumPtsMaxK) * 100, 2) : 0;
            $kitchenGeneralAverage = [
                'total_plats_vendus' => $sumPlatsVendus,
                'total_points_reels' => round($sumPtsReelsK, 2),
                'total_points_max' => round($sumPtsMaxK, 2),
                'taux_ecoulement_global' => $globalTauxK . '%',
                'formule_appliquee' => !empty($numPartsK) ? "[(" . implode(' + ', $numPartsK) . ") / (" . implode(' + ', $denPartsK) . ")] * 100%" : "N/A",
                'statut' => $this->evaluatePerformance($globalTauxK)['message'],
                'badge_color' => $this->evaluatePerformance($globalTauxK)['badge_color']
            ];


            // ==========================================
            // SECTION 2 : BAR DISTRIBUTION
            // ==========================================
            $warehouseDist = Warehouse::where('is_bar_warehouse', true)->first();
            $warehouseDistUuid = $warehouseDist?->uuid;
            $globalPlafondMaxDist = 1; $globalNomProduitMaxDist = 'N/A'; $snapshotsToday = [];

            if ($warehouseDistUuid) {
                $snapshotsToday = \App\Models\StockSnapshot::where('snapshot_date', $today)->pluck('initial_quantity', 'product_uuid')->toArray();
                $topDist = \App\Models\ProductPoint::with('product')->where('point_uuid', $warehouseDistUuid)->orderBy('quantity', 'desc')->first();
                if ($topDist) {
                    $globalNomProduitMaxDist = $topDist->product?->name ?? 'N/A';
                    $pUuid = $topDist->produit_uuid ?? $topDist->product_uuid;
                    $valPlafond = $snapshotsToday[$pUuid] ?? $topDist->quantity;
                    $globalPlafondMaxDist = max((float) $valPlafond, 1);
                }
            }

            $agentIdsQueryDist = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
                ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                ->whereHas('drinkConfig', fn($q) => $q->where('is_finished_product', true))
                ->whereNotNull('updated_by');

            if ($agentId) $agentIdsQueryDist->where('updated_by', $agentId);
            $agentIdsDist = $agentIdsQueryDist->distinct()->pluck('updated_by');
            $agentsDist = User::whereIn('id', $agentIdsDist)->get();

            $salesDist = OrderRestaurantDrink::with(['drinkConfig.product', 'drinkConfig', 'order', 'virtuals'])
                ->whereIn('updated_by', $agentIdsDist)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                ->whereHas('drinkConfig', fn($q) => $q->where('is_finished_product', true))
                ->get()->groupBy('updated_by');

            $reportDistribution = [];
            $sumBoissonsDist = 0; $sumPtsReelsD = 0; $sumPtsMaxD = 0;
            $numPartsD = []; $denPartsD = [];

            foreach ($agentsDist as $agent) {
                $listeVentes = $salesDist[$agent->id] ?? collect();
                if ($listeVentes->isEmpty()) continue;

                $ptsReelsAgent = 0; $ptsMaxAgent = 0; $totalBoissons = $listeVentes->count();

                foreach ($listeVentes as $vente) {
                    $qteVendue = (float) ($vente->quantity_exactly ?? $vente->quantity);
                    $drinkConfig = $vente->drinkConfig;
                    if (!$drinkConfig) continue;

                    $pUuid = $drinkConfig->product_uuid ?? $drinkConfig->uuid;
                    $matchingVirtual = $vente->virtuals->where('product_uuid', $pUuid)->first();

                    $stockArt = ($matchingVirtual && isset($matchingVirtual->stock_at_billing))
                        ? (float) $matchingVirtual->stock_at_billing
                        : ($snapshotsToday[$pUuid] ?? 0);

                    $plafondArt = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing))
                        ? (float) $matchingVirtual->plafond_at_billing
                        : $globalPlafondMaxDist;

                    $calcArt = $qteVendue * 1 * $stockArt;
                    $calcMax = $qteVendue * 1 * $plafondArt;

                    $ptsReelsAgent += $calcArt;
                    $ptsMaxAgent += $calcMax;

                    $pNum = "({$qteVendue} * 1 * {$stockArt})";
                    $pDen = "({$qteVendue} * 1 * {$plafondArt})";
                    $numPartsD[] = $pNum; $denPartsD[] = $pDen;
                }

                $tauxAgent = $ptsMaxAgent > 0 ? round(($ptsReelsAgent / $ptsMaxAgent) * 100, 2) : 0;
                $eval = $this->evaluatePerformance($tauxAgent);

                $sumBoissonsDist += $totalBoissons;
                $sumPtsReelsD += $ptsReelsAgent;
                $sumPtsMaxD += $ptsMaxAgent;

                $reportDistribution[] = [
                    'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                    'boissons_vendues' => $totalBoissons,
                    'points_reels' => round($ptsReelsAgent, 2),
                    'points_max' => round($ptsMaxAgent, 2),
                    'taux_ecoulement' => $tauxAgent . '%',
                    'statut' => $eval['message'],
                    'badge_color' => $eval['badge_color']
                ];
            }

            $globalTauxD = $sumPtsMaxD > 0 ? round(($sumPtsReelsD / $sumPtsMaxD) * 100, 2) : 0;
            $distributionGeneralAverage = [
                'total_boissons_vendues' => $sumBoissonsDist,
                'total_points_reels' => round($sumPtsReelsD, 2),
                'total_points_max' => round($sumPtsMaxD, 2),
                'taux_ecoulement_global' => $globalTauxD . '%',
                'formule_appliquee' => !empty($numPartsD) ? "[(" . implode(' + ', $numPartsD) . ") / (" . implode(' + ', $denPartsD) . ")] * 100%" : "N/A",
                'statut' => $this->evaluatePerformance($globalTauxD)['message'],
                'badge_color' => $this->evaluatePerformance($globalTauxD)['badge_color']
            ];


            // ==========================================
            // SECTION 3 : BAR TRANSFORMATION
            // ==========================================
            $warehouseTrans = Warehouse::where('is_used_for_drinks_transformation', true)->first();
            $warehouseTransUuid = $warehouseTrans?->uuid;
            $globalPlafondMaxTrans = 1; $globalNomProduitMaxTrans = 'N/A';

            if ($warehouseTransUuid) {
                $topTrans = \App\Models\ProductPoint::with('product')->where('point_uuid', $warehouseTransUuid)->orderBy('quantity', 'desc')->first();
                if ($topTrans) {
                    $globalNomProduitMaxTrans = $topTrans->product?->name ?? 'N/A';
                    $globalPlafondMaxTrans = max((float) $topTrans->quantity, 1);
                }
            }

            $agentIdsQueryTrans = OrderRestaurantDrink::whereBetween('created_at', [$startDate, $endDate])
                ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                ->whereHas('drinkConfig', fn($q) => $q->where('is_transformable_product', true))
                ->whereNotNull('updated_by');

            if ($agentId) $agentIdsQueryTrans->where('updated_by', $agentId);
            $agentIdsTrans = $agentIdsQueryTrans->distinct()->pluck('updated_by');
            $agentsTrans = User::whereIn('id', $agentIdsTrans)->get();

            $salesTrans = OrderRestaurantDrink::with(['drinkConfig.composition.items.product', 'order', 'virtuals'])
                ->whereIn('updated_by', $agentIdsTrans)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereHas('order', fn($q) => $q->where('status', MenuOrderStatus::FACTURATE->value))
                ->whereHas('drinkConfig', fn($q) => $q->where('is_transformable_product', true))
                ->get()->groupBy('updated_by');

            $reportTransformation = [];
            $sumBoissonsTrans = 0; $sumPtsReelsT = 0; $sumPtsMaxT = 0;
            $numPartsT = []; $denPartsT = [];

            foreach ($agentsTrans as $agent) {
                $listeVentes = $salesTrans[$agent->id] ?? collect();
                if ($listeVentes->isEmpty()) continue;

                $ptsReelsAgent = 0; $ptsMaxAgent = 0; $totalBoissons = $listeVentes->count();

                foreach ($listeVentes as $vente) {
                    $qteVendue = (float) ($vente->quantity_exactly ?? $vente->quantity);
                    $drinkConfig = $vente->drinkConfig;
                    if (!$drinkConfig) continue;

                    $items = $drinkConfig->composition?->items;
                    if (!$items || $items->isEmpty()) {
                        $pUuid = $drinkConfig->product_uuid ?? $drinkConfig->uuid;
                        $matchingVirtual = $vente->virtuals->where('product_uuid', $pUuid)->first();
                        $stockArt = ($matchingVirtual && isset($matchingVirtual->stock_at_billing)) ? (float) $matchingVirtual->stock_at_billing : 0;
                        $plafondArt = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing)) ? (float) $matchingVirtual->plafond_at_billing : $globalPlafondMaxTrans;

                        $calcArt = $qteVendue * 1 * $stockArt;
                        $calcMax = $qteVendue * 1 * $plafondArt;
                        $ptsReelsAgent += $calcArt; $ptsMaxAgent += $calcMax;

                        $pNum = "({$qteVendue} * 1 * {$stockArt})";
                        $pDen = "({$qteVendue} * 1 * {$plafondArt})";
                        $numPartsT[] = $pNum; $denPartsT[] = $pDen;
                    } else {
                        foreach ($items as $item) {
                            $qteRecette = (float) $item->quantity_used;
                            $pUuid = $item->product_uuid;
                            $matchingVirtual = $vente->virtuals->where('product_uuid', $pUuid)->first();
                            $stockArt = ($matchingVirtual && isset($matchingVirtual->stock_at_billing)) ? (float) $matchingVirtual->stock_at_billing : 0;
                            $plafondArt = ($matchingVirtual && isset($matchingVirtual->plafond_at_billing)) ? (float) $matchingVirtual->plafond_at_billing : $globalPlafondMaxTrans;

                            $calcArt = $qteVendue * $qteRecette * $stockArt;
                            $calcMax = $qteVendue * $qteRecette * $plafondArt;
                            $ptsReelsAgent += $calcArt; $ptsMaxAgent += $calcMax;

                            $pNum = "({$qteVendue} * {$qteRecette} * {$stockArt})";
                            $pDen = "({$qteVendue} * {$qteRecette} * {$plafondArt})";
                            $numPartsT[] = $pNum; $denPartsT[] = $pDen;
                        }
                    }
                }

                $tauxAgent = $ptsMaxAgent > 0 ? round(($ptsReelsAgent / $ptsMaxAgent) * 100, 2) : 0;
                $eval = $this->evaluatePerformance($tauxAgent);

                $sumBoissonsTrans += $totalBoissons;
                $sumPtsReelsT += $ptsReelsAgent;
                $sumPtsMaxT += $ptsMaxAgent;

                $reportTransformation[] = [
                    'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                    'boissons_vendues' => $totalBoissons,
                    'points_reels' => round($ptsReelsAgent, 2),
                    'points_max' => round($ptsMaxAgent, 2),
                    'taux_ecoulement' => $tauxAgent . '%',
                    'statut' => $eval['message'],
                    'badge_color' => $eval['badge_color']
                ];
            }

            $globalTauxT = $sumPtsMaxT > 0 ? round(($sumPtsReelsT / $sumPtsMaxT) * 100, 2) : 0;
            $transformationGeneralAverage = [
                'total_boissons_vendues' => $sumBoissonsTrans,
                'total_points_reels' => round($sumPtsReelsT, 2),
                'total_points_max' => round($sumPtsMaxT, 2),
                'taux_ecoulement_global' => $globalTauxT . '%',
                'formule_appliquee' => !empty($numPartsT) ? "[(" . implode(' + ', $numPartsT) . ") / (" . implode(' + ', $denPartsT) . ")] * 100%" : "N/A",
                'statut' => $this->evaluatePerformance($globalTauxT)['message'],
                'badge_color' => $this->evaluatePerformance($globalTauxT)['badge_color']
            ];


            $fileName = 'RAPPORT-GLOBAL-TAUX-DECOULEMENT-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/agent_stock_flow';
            $filePath = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'start_date' => $startDate->format('d/m/Y'),
                'end_date' => $endDate->format('d/m/Y'),
                'kitchen' => [
                    'performance' => $reportKitchen,
                    'general_average' => $kitchenGeneralAverage
                ],
                'distribution' => [
                    'performance' => $reportDistribution,
                    'general_average' => $distributionGeneralAverage
                ],
                'transformation' => [
                    'performance' => $reportTransformation,
                    'general_average' => $transformationGeneralAverage
                ],
            ];

            save_browser_shot_pdf(
                view: 'pdfs.agent_stock_flow.agent_stock_flow',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [5, 5, 5, 5],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'status'   => 'success',
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erreur génération PDF Global Taux d\'écoulement : ' . $e->getMessage() . ' ligne ' . $e->getLine());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF global',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

}

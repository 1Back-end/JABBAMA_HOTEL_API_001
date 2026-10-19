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
        $startDate = $request->input('start_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay()
            : now()->endOfDay();

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

        if ($restaurantWarehouseUuid) {
            $topProductPointGlobal = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $restaurantWarehouseUuid)
                ->orderBy('quantity', 'desc')
                ->first();

            if ($topProductPointGlobal) {
                $globalPlafondMax = (float) $topProductPointGlobal->quantity;
                $globalNomProduitMax = $topProductPointGlobal->product ? $topProductPointGlobal->product->name : 'N/A';
            }
        }

        if ($globalPlafondMax <= 0) {
            $globalPlafondMax = 1;
        }

        foreach ($agents as $agent) {
            $listeVentes = OrderMenuRestaurantItem::with(['menu.compositionItems.product', 'menu'])
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

                    $stockInitialArt = 0;
                    if ($restaurantWarehouseUuid) {
                        $stockInitialArt = (float) \App\Models\ProductPoint::where('point_uuid', $restaurantWarehouseUuid)
                            ->where('produit_uuid', $productUuid)
                            ->value('quantity') ?? 0;
                    }

                    $stockReferenceArticles[strtolower($nomProduit)] = $stockInitialArt;

                    $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                    $pointsReelsAgent += $calculPointArt;

                    $calculPointMax = $quantiteVendue * $qteDansRecette * $globalPlafondMax;
                    $pointsMaximumPossibles += $calculPointMax;

                    $strNumeratorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                    $strDenominatorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$globalPlafondMax})";

                    $compositionDetails[] = [
                        'ingredient' => strtolower($nomProduit),
                        'quantite_vendue' => $quantiteVendue,
                        'quantite_recette' => $qteDansRecette,
                        'stock_article' => $stockInitialArt,
                        'calcul_point' => round($calculPointArt, 2)
                    ];
                }
            }

            $tauxEcoulementGlobal = $pointsMaximumPossibles > 0
                ? round(($pointsReelsAgent / $pointsMaximumPossibles) * 100, 2)
                : 0;

            $formuleString = "[(" . implode(' + ', $strNumeratorParts) . ") / (" . implode(' + ', $strDenominatorParts) . ")] * 100%";

            $evaluation = $this->evaluatePerformance($tauxEcoulementGlobal);

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

        return response()->json([
            'success' => true,
            'message' => 'Rapport détaillé du taux d\'écoulement basé sur l\'entrepôt cuisine.',
            'info_stock_global' => [
                'entrepot_cuisine' => $nomEntrepotMax,
                'produit_le_plus_eleve' => $globalNomProduitMax,
                'plafond_valeur_max' => $globalPlafondMax
            ],
            'data' => [
                'start_date' => $startDate->format('d-m-Y'),
                'end_date' => $endDate->format('d-m-Y'),
                'filtered_by_agent_id' => $agentId ?? 'Tous les agents',
                'agents_performance' => $report
            ]
        ]);
    }
    public function indexBarDistribution(Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        $agentId = $request->input('agent_id');

        $warehouse = Warehouse::where('is_bar_warehouse', true)->first();
        $warehouseUuid = $warehouse?->uuid;

        $globalPlafondMax = 1;
        $globalNomProduitMax = 'N/A';
        if ($warehouseUuid) {
            $topDist = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $warehouseUuid)
                ->orderBy('quantity', 'desc')
                ->first();
            if ($topDist) {
                $globalPlafondMax = (float) max($topDist->quantity, 1);
                $globalNomProduitMax = $topDist->product?->name ?? 'N/A';
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
                    'agents_performance' => []
                ]
            ]);
        }

        $agents = User::whereIn('id', $agentIds)->get();

        $salesByAgent = OrderRestaurantDrink::with(['drinkConfig.product', 'drinkConfig', 'order'])
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

                $stockInitialArt = (float) ($stockPoints[$productUuid] ?? ($stockPoints[$drinkConfig->uuid] ?? 0));
                $stockReferenceArticles[strtolower($nomProduit)] = $stockInitialArt;

                $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                $pointsReelsAgent += $calculPointArt;

                $calculPointMax = $quantiteVendue * $qteDansRecette * $globalPlafondMax;
                $pointsMaximumPossibles += $calculPointMax;

                $strNumeratorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                $strDenominatorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$globalPlafondMax})";

                $compositionDetails[] = [
                    'boisson' => strtolower($nomProduit),
                    'code_commande' => $codeCommande,
                    'quantite_vendue' => $quantiteVendue,
                    'stock_article' => $stockInitialArt,
                    'calcul_point' => round($calculPointArt, 2)
                ];
            }

            $tauxEcoulementGlobal = $pointsMaximumPossibles > 0
                ? round(($pointsReelsAgent / $pointsMaximumPossibles) * 100, 2)
                : 0;

            $formuleString = "[(" . implode(' + ', $strNumeratorParts) . ") / (" . implode(' + ', $strDenominatorParts) . ")] * 100%";
            $evaluation = $this->evaluatePerformance($tauxEcoulementGlobal);

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
                'agents_performance' => $report
            ]
        ]);
    }

    public function indexBarTransformation(Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        $agentId = $request->input('agent_id');

        $warehouse = Warehouse::where('is_used_for_drinks_transformation', true)->first();
        $warehouseUuid = $warehouse?->uuid;

        $globalPlafondMax = 1;
        $globalNomProduitMax = 'N/A';
        if ($warehouseUuid) {
            $topTrans = \App\Models\ProductPoint::with('product')
                ->where('point_uuid', $warehouseUuid)
                ->orderBy('quantity', 'desc')
                ->first();
            if ($topTrans) {
                $globalPlafondMax = (float) max($topTrans->quantity, 1);
                $globalNomProduitMax = $topTrans->product?->name ?? 'N/A';
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
                    'agents_performance' => []
                ]
            ]);
        }

        $agents = User::whereIn('id', $agentIds)->get();

        $salesByAgent = OrderRestaurantDrink::with([
            'drinkConfig.product',
            'drinkConfig.composition.items.product',
            'order'
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
                    $stockInitialArt = (float) ($stockPoints[$productUuid] ?? ($stockPoints[$drinkConfig->uuid] ?? 0));

                    $stockReferenceArticles[strtolower($nomBoisson)] = $stockInitialArt;

                    $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                    $pointsReelsAgent += $calculPointArt;

                    $calculPointMax = $quantiteVendue * $qteDansRecette * $globalPlafondMax;
                    $pointsMaximumPossibles += $calculPointMax;

                    $strNumeratorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                    $strDenominatorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$globalPlafondMax})";

                    $compositionDetails[] = [
                        'boisson' => strtolower($nomBoisson),
                        'code_commande' => $codeCommande,
                        'quantite_vendue' => $quantiteVendue,
                        'stock_article' => $stockInitialArt,
                        'calcul_point' => round($calculPointArt, 2)
                    ];
                } else {
                    foreach ($items as $item) {
                        $qteDansRecette = (float) $item->quantity_used;
                        $productUuid = $item->product_uuid;
                        $nomIngredient = $item->product?->name ?? 'Ingrédient';
                        $stockInitialArt = (float) ($stockPoints[$productUuid] ?? 0);

                        $stockReferenceArticles[strtolower($nomIngredient)] = $stockInitialArt;

                        $calculPointArt = $quantiteVendue * $qteDansRecette * $stockInitialArt;
                        $pointsReelsAgent += $calculPointArt;

                        $calculPointMax = $quantiteVendue * $qteDansRecette * $globalPlafondMax;
                        $pointsMaximumPossibles += $calculPointMax;

                        $strNumeratorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$stockInitialArt})";
                        $strDenominatorParts[] = "({$quantiteVendue} * {$qteDansRecette} * {$globalPlafondMax})";

                        $compositionDetails[] = [
                            'boisson' => strtolower($nomBoisson),
                            'ingredient' => strtolower($nomIngredient),
                            'code_commande' => $codeCommande,
                            'quantite_vendue' => $quantiteVendue,
                            'quantite_recette' => $qteDansRecette,
                            'stock_article' => $stockInitialArt,
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
                'agents_performance' => $report
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

}

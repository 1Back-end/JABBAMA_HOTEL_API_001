<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Models\OrderMenuRestaurantItem;
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

        // 1. Récupération de l'entrepôt cuisine
        $restaurantWarehouse = Warehouse::where('is_used_for_restaurant', true)->first();
        $nomEntrepotMax = $restaurantWarehouse ? $restaurantWarehouse->name : 'N/A';
        $restaurantWarehouseUuid = $restaurantWarehouse ? $restaurantWarehouse->uuid : null;

        $report = [];
        $globalNomProduitMax = 'N/A';
        $globalPlafondMax = 0;

        // 2. Recherche globale du produit avec le stock le plus élevé DANS CET ENTREPÔT CUISINE
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

                    // Récupération du stock de l'article STRICTEMENT DANS L'ENTREPÔT CUISINE
                    $stockInitialArt = 0;
                    if ($restaurantWarehouseUuid) {
                        $stockInitialArt = (float) \App\Models\ProductPoint::where('point_uuid', $restaurantWarehouseUuid)
                            ->where('produit_uuid', $productUuid)
                            ->value('quantity') ?? 0;
                    }

                    $stockReferenceArticles[strtolower($nomProduit)] = $stockInitialArt;

                    // Calculs réels et maximums avec les opérateurs de multiplication corrects (*)
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

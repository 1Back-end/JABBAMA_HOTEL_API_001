<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Models\OrderMenuRestaurantItem;
use App\Models\Product;
use App\Models\ProductPoint;
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

        $report = [];
        $globalNomProduitMax = 'N/A';
        $globalPlafondMax = 0;

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
                    'statut' => 'Bar uniquement: Pas de vente de restauration sur ce service'
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

                $saleDate = Carbon::parse($vente->created_at)->toDateString();

                $topSnapshotGlobal = \App\Models\StockSnapshot::where('snapshot_date', $saleDate)
                    ->orderBy('initial_quantity', 'desc')
                    ->with('product')
                    ->first();

                $plusGrosStockInitialGlobal = $topSnapshotGlobal ? (float) $topSnapshotGlobal->initial_quantity : 1;
                $nomProduitMax = ($topSnapshotGlobal && $topSnapshotGlobal->product) ? $topSnapshotGlobal->product->name : 'N/A';

                $globalPlafondMax = max($globalPlafondMax, $plusGrosStockInitialGlobal);
                if ($nomProduitMax !== 'N/A') {
                    $globalNomProduitMax = $nomProduitMax;
                }

                if ($plusGrosStockInitialGlobal <= 0) {
                    $plusGrosStockInitialGlobal = 1;
                }

                foreach ($menu->compositionItems as $item) {
                    $qteDansRecette = (float) $item->quantity_used;
                    $productUuid = $item->product_uuid;
                    $nomProduit = $item->product ? $item->product->name : '';

                    $snapshot = \App\Models\StockSnapshot::where('snapshot_date', $saleDate)
                        ->where('product_uuid', $productUuid)
                        ->first();

                    $stockInitialArt = $snapshot
                        ? (float) $snapshot->initial_quantity
                        : (float) ProductPoint::where('produit_uuid', $productUuid)->value('quantity');

                    $stockReferenceArticles[strtolower($nomProduit)] = $stockInitialArt;

                    $calculPointArt = ($quantiteVendue * $qteDansRecette * $stockInitialArt);
                    $pointsReelsAgent += $calculPointArt;

                    $calculPointMax = ($quantiteVendue * $qteDansRecette * $plusGrosStockInitialGlobal);
                    $pointsMaximumPossibles += $calculPointMax;

                    $strNumeratorParts[] = "({$quantiteVendue}*{$qteDansRecette}*{$stockInitialArt})";
                    $strDenominatorParts[] = "({$quantiteVendue}*{$qteDansRecette}*{$plusGrosStockInitialGlobal})";

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
                'statut' => $this->evaluatePerformance($tauxEcoulementGlobal)
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Rapport détaillé du taux d\'écoulement basé sur les snapshots.',
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

    private function evaluatePerformance(float $taux): string
    {
        if ($taux >= 80) {
            $tauxText = rtrim(rtrim(number_format($taux, 1), '0'), '.');
            return "Excellent: A orienté {$tauxText}% de ses ventes vers le plus gros surstock.";
        } elseif ($taux >= 40) {
            return "Standard: Fait du volume, mais a vendu autant de petits stocks que de gros stocks.";
        }
        return "À améliorer: Ventes orientées vers des stocks non critiques.";
    }
}

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
            ->whereNotNull('created_by');

        if ($agentId) {
            $agentIdsQuery->where('created_by', $agentId);
        }

        $agentIds = $agentIdsQuery->distinct()->pluck('created_by');

        $agents = User::whereIn('id', $agentIds)->get();

        $restaurantWarehouse = Warehouse::where('is_used_for_restaurant', true)->first();

        $nomEntrepotMax = $restaurantWarehouse ? $restaurantWarehouse->name : 'N/A';

        $plusGrosStockInitial = 0;
        $nomProduitMax = 'N/A';

        if ($restaurantWarehouse) {
            $topProductPivot = $restaurantWarehouse->products()
                ->where('produit_point.is_active', true)
                ->orderByPivot('quantity', 'desc')
                ->first();

            if ($topProductPivot) {
                $plusGrosStockInitial = (float) $topProductPivot->pivot->quantity;
                $nomProduitMax = $topProductPivot->name;
            }
        } else {
            $topProductPoint = ProductPoint::where('is_active', true)
                ->orderBy('quantity', 'desc')
                ->with('product')
                ->first();

            if ($topProductPoint) {
                $plusGrosStockInitial = (float) $topProductPoint->quantity;
                $nomProduitMax = $topProductPoint->product ? $topProductPoint->product->name : 'N/A';
            }
        }

        $productPoints = ProductPoint::pluck('quantity', 'produit_uuid')->toArray();
        $fallbackStock = Product::pluck('stock_quantity', 'uuid')->toArray();

        $report = [];

        foreach ($agents as $agent) {
            $listeVentes = OrderMenuRestaurantItem::with(['menu.compositionItems.product'])
                ->where('created_by', $agent->id)
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
                    'statut' => 'Bar uniquement: Pas de vente de restauration sur ce service'
                ];
                continue;
            }

            $pointsReelsAgent = 0;
            $pointsMaximumPossibles = 0;

            $totalPlatsVendus = (int) $listeVentes->count();

            foreach ($listeVentes as $vente) {
                $quantiteVendue = (float) $vente->quantity_exactly;
                $menu = $vente->menu;

                if (!$menu || $menu->compositionItems->isEmpty()) {
                    continue;
                }

                foreach ($menu->compositionItems as $item) {
                    $qteDansRecette = (float) $item->quantity_used;
                    $productUuid = $item->product_uuid;
                    $stockInitialArt = (float) ($productPoints[$productUuid] ?? ($fallbackStock[$productUuid] ?? 0));

                    $pointsReelsAgent += ($quantiteVendue * $qteDansRecette * $stockInitialArt);
                }

                $pointsMaximumPossibles += ($quantiteVendue * $plusGrosStockInitial);
            }

            $tauxEcoulementGlobal = $pointsMaximumPossibles > 0
                ? round(($pointsReelsAgent / $pointsMaximumPossibles) * 100, 2)
                : 0;

            $report[] = [
                'agent_id' => $agent->id,
                'agent_name' => $agent->nom_utilisateur ?? $agent->email,
                'plats_vendus' => $totalPlatsVendus,
                'points_reels' => round($pointsReelsAgent, 2),
                'points_max' => round($pointsMaximumPossibles, 2),
                'taux_ecoulement' => $tauxEcoulementGlobal . '%',
                'statut' => $this->evaluatePerformance($tauxEcoulementGlobal)
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Rapport du taux d\'écoulement des stocks par agent généré avec succès.',
            'info_stock_global' => [
                'entrepot_cuisine' => $nomEntrepotMax,
                'produit_le_plus_eleve' => $nomProduitMax,
                'plafond_valeur_max' => $plusGrosStockInitial
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

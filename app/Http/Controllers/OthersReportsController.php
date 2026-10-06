<?php

namespace App\Http\Controllers;

use App\Models\OrderMenuRestaurantItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OthersReportsController extends Controller
{
    public function index(Request $request)
    {
        try {
            $request->validate([
                'menu_uuid'  => 'required|uuid|exists:menus_restaurants,uuid',
                'start_date' => 'required|date_format:d-m-Y',
                'end_date'   => 'required|date_format:d-m-Y|after_or_equal:start_date',
            ]);

            $menuRestaurantUuid = $request->input('menu_uuid');
            $startDateInput = $request->input('start_date');
            $endDateInput = $request->input('end_date');

            $startDate = Carbon::createFromFormat('d-m-Y', $startDateInput)->startOfDay()->toDateTimeString();
            $endDate = Carbon::createFromFormat('d-m-Y', $endDateInput)->endOfDay()->toDateTimeString();

            $items = OrderMenuRestaurantItem::with(['order', 'menu', 'complements.complement'])
                ->where('menus_restaurant_uuid', $menuRestaurantUuid)
                ->where('status', \App\Enums\OrderMenuRestaurantItemStatus::DELIVERED->value)
                ->whereHas('order', function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('order_menu_restaurant_date', [$startDate, $endDate])
                        ->where('status', \App\Enums\MenuOrderStatus::FACTURATE->value);
                })
                ->get();

            $totalNumerator = 0;
            $totalDenominatorWeightedSum = 0;
            $totalQuantity = 0;
            $itemsDetails = [];

            $numeratorPartsText = [];
            $salesPartsText = [];
            $qtyPartsText = [];
            $intermediateNumeratorParts = [];

            foreach ($items as $item) {
                $quantitySold = $item->quantity_exactly ?? $item->quantity ?? 0;
                $unitSellingPrice = $item->unit_price ?? 0;

                if ($quantitySold <= 0 || $unitSellingPrice <= 0) {
                    Log::warning('[Menu Cost Analysis] Item ignoré (quantité ou prix <= 0)', [
                        'item_uuid' => $item->uuid ?? null,
                    ]);
                    continue;
                }

                $additionalCost             = $item->snapshot_additional_cost ?? 0;
                $menuProductionCost         = $item->snapshot_composition_cost ?? 0;
                $complementsProductionCost  = $item->snapshot_complements_cost ?? 0;

                $complementsList = [];
                foreach ($item->complements as $itemComplement) {
                    $compCost = $itemComplement->complement->additional_cost ?? $itemComplement->complement->production_cost ?? 0;
                    $complementsList[] = [
                        'complement_uuid' => $itemComplement->complement->uuid ?? null,
                        'name'            => $itemComplement->complement->name ?? '',
                        'additional_cost' => $compCost,
                    ];
                }

                $unitProductionCost = $menuProductionCost + $complementsProductionCost;
                $lineNumerator = $quantitySold * $unitProductionCost;
                $totalNumerator += $lineNumerator;

                $lineDenominator = $quantitySold * $unitSellingPrice;
                $totalDenominatorWeightedSum += $lineDenominator;
                $totalQuantity += $quantitySold;

                $orderCode = $item->order->code ?? '';
                $menuName = $item->menu->name ?? 'Menu Inconnu';
                $compNames = collect($complementsList)->pluck('name')->implode(', ');

                $compText = $complementsProductionCost > 0
                    ? "+ {$complementsProductionCost} (prix de production du complement {$compNames})"
                    : "";

                $numeratorPartsText[] = "{$quantitySold}x [{$menuProductionCost} (prix de production du menu {$menuName}){$compText}](fact#{$orderCode})";
                $salesPartsText[] = "{$unitSellingPrice}x{$quantitySold}";
                $qtyPartsText[] = $quantitySold;
                $intermediateNumeratorParts[] = $lineNumerator;

                $itemsDetails[] = [
                    'order_code'                  => $orderCode,
                    'order_date'                  => $item->order->order_menu_restaurant_date ?? '',
                    'quantity_sold'               => $quantitySold,
                    'menu_production_cost'        => $menuProductionCost,
                    'additional_cost'             => $additionalCost,
                    'composition_items_cost'      => $menuProductionCost - $additionalCost,
                    'complements_production_cost' => $complementsProductionCost,
                    'complements_details'         => $complementsList,
                    'unit_production_cost'        => $unitProductionCost,
                    'total_line_production_cost'  => $lineNumerator,
                    'unit_selling_price'          => $unitSellingPrice,
                    'total_line_sales'            => $lineDenominator,
                ];
            }


            if ($totalQuantity <= 0 || $totalDenominatorWeightedSum <= 0) {
                return response()->json([
                    'status' => 'success',
                    'filters' => [
                        'menu_uuid'  => $menuRestaurantUuid,
                        'start_date' => $startDateInput,
                        'end_date'   => $endDateInput,
                    ],
                    'data' => [
                        'total_production_cost_numerator'  => 0,
                        'total_weighted_sales_denominator' => 0,
                        'total_quantity_sold'              => 0,
                        'total_margin_amount'              => 0,
                        'production_cost_percentage'       => 0,
                        'margin_percentage'                => 0,
                        'menu_composition_items'           => [],
                        'items_details'                    => [],
                        'formula_breakdown'                => null,
                    ],
                ]);
            }

            $averageWeightedPrice = $totalDenominatorWeightedSum / $totalQuantity;
            $denominatorFinal = $totalQuantity * $averageWeightedPrice;

            $productionCostRatio = ($totalNumerator / $denominatorFinal) * 100;
            $marginRatio = 100 - $productionCostRatio;
            $totalMarginAmount = $denominatorFinal - $totalNumerator;

            // --- Construction finale de la formule breakdown exacte ---
            $numeratorString = '{' . implode(' + ', $numeratorPartsText) . '}';
            $qtyString = '[' . implode('+', $qtyPartsText) . ']';
            $salesString = '[' . implode(' +', $salesPartsText) . ']';
            $intermediateNumeratorString = '{' . implode('+ ', $intermediateNumeratorParts) . '}';

            $formulaFormatted = "{$numeratorString}\n"
                . "/ { {$qtyString} (quantité respective vendu) X {{$salesString} (somme des prix de vente de vente respectif X les ponderations respective de chaque vente)\n"
                . "/{$qtyString} (somme ponderations respective de chaque vente)}} x 100%\n\n"
                . "= {$intermediateNumeratorString}/ {{$totalQuantity}X ({$totalDenominatorWeightedSum}/{$totalQuantity})} x 100%\n"
                . "={$totalNumerator}/{$denominatorFinal} x 100%";

            return response()->json([
                'status' => 'success',
                'filters' => [
                    'menu_uuid'  => $menuRestaurantUuid,
                    'start_date' => $startDateInput,
                    'end_date'   => $endDateInput,
                ],
                'data' => [
                    'total_production_cost_numerator'  => $totalNumerator,
                    'total_weighted_sales_denominator' => $denominatorFinal,
                    'total_quantity_sold'              => $totalQuantity,
                    'total_margin_amount'              => $totalMarginAmount,
                    'production_cost_percentage'       => round($productionCostRatio, 2),
                    'margin_percentage'                => round($marginRatio, 2),
                    'menu_composition_items'           => [],
                    'items_details'                    => $itemsDetails,
                    'formula_breakdown'                => $formulaFormatted,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('[Menu Cost Analysis] Erreur de validation', ['errors' => $e->errors()]);
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('[Menu Cost Analysis] Erreur critique inattendue', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du calcul.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\OrderMenuRestaurantItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

            $menuOrder = \App\Models\MenuOrder::where('menus_restaurant_uuid', $menuRestaurantUuid)
                ->with('items.product')
                ->first();

            $compositionItemsCost = 0;
            $compositionDetails = [];

            if ($menuOrder && $menuOrder->items) {
                foreach ($menuOrder->items as $compItem) {
                    $productUuid = $compItem->product_uuid;
                    $quantityUsed = $compItem->quantity_used ?? 0;

                    $supplyItems = DB::table('supply_items as si')
                        ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                        ->where('si.product_uuid', $productUuid)
                        ->whereNotNull('si.unit_price')
                        ->whereNull('si.deleted_at')
                        ->whereIn('s.status', [
                            \App\Enums\SupplyStatus::VALIDATED->value,
                            \App\Enums\SupplyStatus::PARTIALLY_VALIDATED->value
                        ])
                        ->where('s.supply_date', '<=', $endDate)
                        ->where('si.quantity_supplied', '>', 0)
                        ->select('si.unit_price', 'si.quantity_supplied')
                        ->get();

                    $totalValue = $supplyItems->sum(fn($s) => floatval($s->unit_price) * floatval($s->quantity_supplied));
                    $totalQtySupplied = $supplyItems->sum(fn($s) => floatval($s->quantity_supplied));

                    $averageUnitPrice = $totalQtySupplied > 0 ? ($totalValue / $totalQtySupplied) : ($compItem->product->purchase_cost ?? 0);

                    $lineComponentCost = $quantityUsed * $averageUnitPrice;
                    $compositionItemsCost += $lineComponentCost;

                    $compositionDetails[] = [
                        'product_uuid'       => $productUuid,
                        'product_name'       => $compItem->product->name ?? 'Inconnu',
                        'quantity_used'      => $quantityUsed,
                        'average_unit_price' => round($averageUnitPrice, 2),
                        'total_cost'         => round($lineComponentCost, 2),
                    ];
                }
            }

            $items = OrderMenuRestaurantItem::with(['order', 'menu', 'complements.complement'])
                ->where('menus_restaurant_uuid', $menuRestaurantUuid)
                ->whereHas('order', function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('created_at', [$startDate, $endDate])
                        ->where('status', '!=', \App\Enums\MenuOrderStatus::CANCELLED->value);
                })
                ->get();

            $totalNumerator = 0;
            $totalDenominatorWeightedSum = 0;
            $totalQuantity = 0;
            $itemsDetails = [];
            $numeratorPartsText = [];
            $salesPartsText = [];

            foreach ($items as $item) {
                $quantitySold = $item->quantity_exactly ?? $item->quantity ?? 0;

                if ($quantitySold <= 0) {
                    continue;
                }

                $additionalCost = $item->menu->additional_cost ?? 0;
                $menuProductionCost = $additionalCost + $compositionItemsCost;

                $complementsProductionCost = 0;
                $complementsList = [];
                foreach ($item->complements as $itemComplement) {
                    $compCost = $itemComplement->complement->additional_cost ?? $itemComplement->complement->production_cost ?? 0;
                    $complementsProductionCost += $compCost;
                    $complementsList[] = [
                        'complement_uuid' => $itemComplement->complement->uuid ?? null,
                        'name'            => $itemComplement->complement->name ?? '',
                        'additional_cost' => $compCost,
                    ];
                }

                $unitProductionCost = $menuProductionCost + $complementsProductionCost;
                $lineNumerator = $quantitySold * $unitProductionCost;
                $totalNumerator += $lineNumerator;

                $unitSellingPrice = $item->unit_price ?? 0;
                $lineDenominator = $quantitySold * $unitSellingPrice;
                $totalDenominatorWeightedSum += $lineDenominator;
                $totalQuantity += $quantitySold;

                $orderCode = $item->order->code ?? '';
                $compNames = collect($complementsList)->pluck('name')->implode(', ');
                $numeratorPartsText[] = "{$quantitySold}x [{$menuProductionCost} (coût menu: additionnel {$additionalCost} + composants {$compositionItemsCost}) + {$complementsProductionCost} (compléments additionnels: {$compNames})] (fact#{$orderCode})";
                $salesPartsText[] = "{$unitSellingPrice}x{$quantitySold}";

                $itemsDetails[] = [
                    'order_code'                  => $orderCode,
                    'order_date'                  => $item->order->order_menu_restaurant_date ?? '',
                    'quantity_sold'               => $quantitySold,
                    'menu_production_cost'        => $menuProductionCost,
                    'additional_cost'             => $additionalCost,
                    'composition_items_cost'      => $compositionItemsCost,
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
                        'production_cost_percentage'       => 0,
                        'margin_percentage'                => 0,
                        'menu_composition_items'           => $compositionDetails,
                        'items_details'                    => [],
                        'formula_breakdown'                => null,
                    ],
                ]);
            }

            $averageWeightedPrice = $totalDenominatorWeightedSum / $totalQuantity;
            $denominatorFinal = $totalQuantity * $averageWeightedPrice;

            $productionCostRatio = ($totalNumerator / $denominatorFinal) * 100;
            $marginRatio = 100 - $productionCostRatio;

            $numeratorString = '{' . implode(' + ', $numeratorPartsText) . '}';
            $salesString = implode(' + ', $salesPartsText);
            $formulaFormatted = "{$numeratorString} / { [{$totalQuantity}] (quantité respective vendue) X {[{$salesString}] (somme des prix de vente respectifs X les pondérations respectives)} x 100% = {$totalNumerator} / {$denominatorFinal} x 100% = " . round($productionCostRatio, 2) . "%";

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
                    'production_cost_percentage'       => round($productionCostRatio, 2),
                    'margin_percentage'                => round($marginRatio, 2),
                    'menu_composition_items'           => $compositionDetails,
                    'items_details'                    => $itemsDetails,
                    'formula_breakdown'                => $formulaFormatted,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du calcul.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}

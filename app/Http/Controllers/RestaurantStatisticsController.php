<?php

namespace App\Http\Controllers;

use App\Enums\OrderMenuRestaurantItemStatus;
use App\Models\StatisticsOrderStatusMenuRestaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RestaurantStatisticsController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay()
            : Carbon::today()->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay()
            : Carbon::today()->endOfDay();

        $agentId = $request->input('agent_id');

        $query = StatisticsOrderStatusMenuRestaurant::with([
            'orderItem.menu',
            'agentInPreparation',
            'agentServed',
            'agentReject',
            'agentCancelForNewUpdate'
        ])
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($agentId) {
            $query->where(function($q) use ($agentId) {
                $q->where('make_in_preparation_by', $agentId)
                    ->orWhere('make_delivered_by', $agentId)
                    ->orWhere('rejected_by', $agentId)
                    ->orWhere('make_rejected_by', $agentId)
                    ->orWhere('make_cancel_for_new_update_by', $agentId);
            });
        }

        $statistics = $query->get();
        $groupedByItem = $statistics->groupBy('order_menu_restaurant_item_uuid');

        $cookingItemDurations = [];
        $takeoverItemDurations = [];
        $servingReadyItemDurations = [];
        $totalDeliveryItemDurations = [];

        $rejectionData = [];
        $malConfectionData = [];

        foreach ($groupedByItem as $stats) {
            $firstStat = $stats->first();
            $orderItem = $firstStat->orderItem;
            if (!$orderItem || !$orderItem->menu) {
                continue;
            }

            $dishUuid = $orderItem->menus_restaurant_uuid;
            $dishName = $orderItem->menu->name;

            $orderedQuantity = (int) ($orderItem->quantity_exactly ?? $orderItem->quantity_final_used ?? 1);

            $rejectionStat = $stats->filter(function ($item) {
                return (!empty($item->status) && $item->status === OrderMenuRestaurantItemStatus::REJECTED->value)
                    || !empty($item->rejected_at)
                    || !empty($item->make_rejected_by);
            })->first();

            $malConfectionStat = $stats->filter(function ($item) {
                return (!empty($item->status) && $item->status === OrderMenuRestaurantItemStatus::REJECTED_FOR_NEW_UPDATE->value)
                    || !empty($item->cancel_for_new_update_at)
                    || !empty($item->make_cancel_for_new_update_by);
            })->first();

            $rejectAgentUuid = $rejectionStat?->make_rejected_by ?? $rejectionStat?->rejected_by;
            $rejectAgentName = 'Non défini';
            if ($rejectionStat && $rejectionStat->agentReject) {
                $rejectAgentName = $rejectionStat->agentReject->nom_utilisateur
                    ?? $rejectionStat->agentReject->name
                    ?? 'Agent #' . $rejectAgentUuid;
            } elseif ($rejectAgentUuid) {
                $rejectAgentName = 'Agent #' . $rejectAgentUuid;
            }

            $malConfectionAgentUuid = $malConfectionStat?->make_cancel_for_new_update_by;
            $malConfectionAgentName = 'Non défini';
            if ($malConfectionStat && $malConfectionStat->agentCancelForNewUpdate) {
                $userMal = $malConfectionStat->agentCancelForNewUpdate;
                $malConfectionAgentName = $userMal->nom_utilisateur
                    ?? $userMal->name
                    ?? 'Agent #' . $malConfectionAgentUuid;
            } elseif ($malConfectionAgentUuid) {
                $malConfectionAgentName = 'Agent #' . $malConfectionAgentUuid;
            }


            $rejectedQty = $rejectionStat ? (int) ($rejectionStat->quantity ?? 0) : 0;
            $malConfectionQty = $malConfectionStat ? (int) ($malConfectionStat->quantity ?? 0) : 0;

            // Alimentation des ratios
            $rejectionData[] = [
                'agent_uuid' => $rejectAgentUuid,
                'agent_name' => $rejectAgentName,
                'dish_uuid' => $dishUuid,
                'dish_name' => $dishName,
                'ordered_quantity' => $orderedQuantity,
                'rejected_quantity' => $rejectedQty,
            ];

            $malConfectionData[] = [
                'agent_uuid' => $malConfectionAgentUuid,
                'agent_name' => $malConfectionAgentName,
                'dish_uuid' => $dishUuid,
                'dish_name' => $dishName,
                'ordered_quantity' => $orderedQuantity,
                'mal_confection_quantity' => $malConfectionQty,
            ];

            // Exclusion des stats de temps si rejeté ou mal confectionné
            if ($rejectionStat !== null || $malConfectionStat !== null) {
                continue;
            }

            // --- EXTRACTION DES DATES SUR L'ENSEMBLE DES LIGNES DE L'ITEM ---
            $transferredAt = $stats->whereNotNull('transferred_at')->pluck('transferred_at')->first();
            $inPreparationAt = $stats->whereNotNull('in_preparation_at')->pluck('in_preparation_at')->first();
            $readyAt = $stats->whereNotNull('ready_at')->pluck('ready_at')->first();
            $deliveredAt = $stats->whereNotNull('delivered_at')->pluck('delivered_at')->first();

            // Récupération des agents via les relations
            $inPrepStat = $stats->whereNotNull('in_preparation_at')->first();
            $prepAgentUuid = $inPrepStat?->make_in_preparation_by;
            $prepAgentName = 'Non défini';
            if ($inPrepStat && $inPrepStat->agentInPreparation) {
                $prepAgentName = $inPrepStat->agentInPreparation->nom_utilisateur
                    ?? $inPrepStat->agentInPreparation->name
                    ?? ($prepAgentUuid ? 'Agent #' . $prepAgentUuid : 'Non défini');
            } elseif ($prepAgentUuid) {
                $prepAgentName = 'Agent #' . $prepAgentUuid;
            }

            $deliveredStat = $stats->whereNotNull('delivered_at')->first();
            $deliveredAgentUuid = $deliveredStat?->make_delivered_by;
            $deliveredAgentName = 'Non défini';
            if ($deliveredStat && $deliveredStat->agentServed) {
                $deliveredAgentName = $deliveredStat->agentServed->nom_utilisateur
                    ?? $deliveredStat->agentServed->name
                    ?? ($deliveredAgentUuid ? 'Agent #' . $deliveredAgentUuid : 'Non défini');
            } elseif ($deliveredAgentUuid) {
                $deliveredAgentName = 'Agent #' . $deliveredAgentUuid;
            }

            // 1. Durée de Cuisson : in_preparation_at -> ready_at
            if ($inPreparationAt && $readyAt) {
                $cookingItemDurations[] = [
                    'agent_uuid' => $prepAgentUuid,
                    'agent_name' => $prepAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => Carbon::parse($inPreparationAt)->diffInSeconds(Carbon::parse($readyAt)),
                ];
            }

            // 2. Durée de Prise en compte : transferred_at -> in_preparation_at
            if ($transferredAt && $inPreparationAt) {
                $takeoverItemDurations[] = [
                    'agent_uuid' => $prepAgentUuid,
                    'agent_name' => $prepAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => Carbon::parse($transferredAt)->diffInSeconds(Carbon::parse($inPreparationAt)),
                ];
            }

            // 3. Durée pour servir un plat prêt : ready_at -> delivered_at
            if ($readyAt && $deliveredAt) {
                $servingReadyItemDurations[] = [
                    'agent_uuid' => $deliveredAgentUuid,
                    'agent_name' => $deliveredAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => Carbon::parse($readyAt)->diffInSeconds(Carbon::parse($deliveredAt)),
                ];
            }

            // 4. Durée globale pour servir un plat : transferred_at -> delivered_at
            if ($transferredAt && $deliveredAt) {
                $totalDeliveryItemDurations[] = [
                    'agent_uuid' => $deliveredAgentUuid,
                    'agent_name' => $deliveredAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => Carbon::parse($transferredAt)->diffInSeconds(Carbon::parse($deliveredAt)),
                ];
            }
        }

        $formatDuration = function ($averageSeconds) {
            $hours = floor($averageSeconds / 3600);
            $minutes = floor(($averageSeconds / 60) % 60);
            $seconds = $averageSeconds % 60;
            return sprintf('%02d:%02d:%02d', (int)$hours, (int)$minutes, (int)$seconds);
        };

        $calculateAverages = function ($items, $groupByKey) use ($formatDuration) {
            return collect($items)->groupBy($groupByKey)->map(function ($group) use ($formatDuration) {
                $first = $group->first();
                $totalDurationWeighted = 0;
                $totalQuantity = 0;

                foreach ($group as $item) {
                    $totalDurationWeighted += ($item['duration_seconds'] * $item['quantity']);
                    $totalQuantity += $item['quantity'];
                }

                $averageSeconds = $totalQuantity > 0 ? round($totalDurationWeighted / $totalQuantity) : 0;

                $result = [
                    'dish_uuid' => $first['dish_uuid'],
                    'dish_name' => $first['dish_name'],
                    'total_duration_seconds' => $totalDurationWeighted,
                    'total_quantity' => $totalQuantity,
                    'average_duration_seconds' => $averageSeconds,
                    'average_duration_formatted' => $formatDuration($averageSeconds),
                ];

                if (isset($first['agent_uuid'])) {
                    $result['agent_uuid'] = $first['agent_uuid'];
                    $result['agent_name'] = $first['agent_name'];
                }

                return $result;
            })->values();
        };

        $calculateRatio = function ($items, $groupByKey, $quantityKey) {
            return collect($items)->groupBy($groupByKey)->map(function ($group) use ($quantityKey) {
                $first = $group->first();
                $totalOrdered = 0;
                $totalTarget = 0;

                foreach ($group as $item) {
                    $totalOrdered += $item['ordered_quantity'];
                    $totalTarget += $item[$quantityKey];
                }

                $ratio = $totalOrdered > 0 ? round(($totalTarget / $totalOrdered) * 100, 2) : 0.0;

                $result = [
                    'dish_uuid' => $first['dish_uuid'],
                    'dish_name' => $first['dish_name'],
                    'total_ordered_quantity' => $totalOrdered,
                    'target_quantity' => $totalTarget,
                    'ratio_percentage' => $ratio,
                ];

                if (isset($first['agent_uuid'])) {
                    $result['agent_uuid'] = $first['agent_uuid'];
                    $result['agent_name'] = $first['agent_name'];
                }

                return $result;
            })->values();
        };

        $averageCookingPerDish = $calculateAverages($cookingItemDurations, 'dish_uuid');
        $averageCookingPerDishAndAgent = $calculateAverages($cookingItemDurations, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid']);

        $averageTakeoverPerDish = $calculateAverages($takeoverItemDurations, 'dish_uuid');
        $averageTakeoverPerDishAndAgent = $calculateAverages($takeoverItemDurations, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid']);

        $averageServingReadyPerDish = $calculateAverages($servingReadyItemDurations, 'dish_uuid');
        $averageServingReadyPerDishAndAgent = $calculateAverages($servingReadyItemDurations, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid']);

        $averageTotalDeliveryPerDish = $calculateAverages($totalDeliveryItemDurations, 'dish_uuid');

        $rejectionRatioPerDish = $calculateRatio($rejectionData, 'dish_uuid', 'rejected_quantity');
        $rejectionRatioPerDishAndAgent = $calculateRatio($rejectionData, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid'], 'rejected_quantity');

        $malConfectionRatioPerDish = $calculateRatio($malConfectionData, 'dish_uuid', 'mal_confection_quantity');
        $malConfectionRatioPerDishAndAgent = $calculateRatio($malConfectionData, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid'], 'mal_confection_quantity');

        return response()->json([
            'status' => 'success',
            'period' => [
                'start_date' => $startDate->format('d-m-Y H:i:s'),
                'end_date' => $endDate->format('d-m-Y H:i:s'),
            ],
            'data' => [
                'average_cooking_per_dish' => $averageCookingPerDish,
                'average_cooking_per_dish_and_agent' => $averageCookingPerDishAndAgent,
                'average_takeover_per_dish' => $averageTakeoverPerDish,
                'average_takeover_per_dish_and_agent' => $averageTakeoverPerDishAndAgent,
                'average_serving_ready_per_dish' => $averageServingReadyPerDish,
                'average_serving_ready_per_dish_and_agent' => $averageServingReadyPerDishAndAgent,
                'average_total_delivery_per_dish' => $averageTotalDeliveryPerDish,
                'rejection_ratio_per_dish' => $rejectionRatioPerDish,
                'rejection_ratio_per_dish_and_agent' => $rejectionRatioPerDishAndAgent,
                'mal_confection_ratio_per_dish' => $malConfectionRatioPerDish,
                'mal_confection_ratio_per_dish_and_agent' => $malConfectionRatioPerDishAndAgent,
            ]
        ]);
    }
}

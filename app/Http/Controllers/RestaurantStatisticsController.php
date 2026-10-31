<?php

namespace App\Http\Controllers;

use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\RestaurantReportType;
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
        $menuUuid = $request->input('menu_uuid') ?? $request->input('menu_id'); // 🔹 Récupération du filtre menu
        $reportType = RestaurantReportType::tryFrom($request->input('report_type')) ?? RestaurantReportType::ALL;

        // 🔹 ÉTAPE 1 : Récupération des UUIDs de la période
        $itemUuidsQuery = StatisticsOrderStatusMenuRestaurant::query()
            ->where(function($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate])
                    ->orWhereBetween('transferred_at', [$startDate, $endDate])
                    ->orWhereBetween('in_preparation_at', [$startDate, $endDate])
                    ->orWhereBetween('ready_at', [$startDate, $endDate])
                    ->orWhereBetween('delivered_at', [$startDate, $endDate]);
            });

        $targetItemUuids = $itemUuidsQuery->pluck('order_menu_restaurant_item_uuid')->unique();

        // 🔹 ÉTAPE 2 : Chargement de toutes les lignes associées à ces plats
        $query = StatisticsOrderStatusMenuRestaurant::with([
            'orderItem.menu',
        ])->whereIn('order_menu_restaurant_item_uuid', $targetItemUuids);

        $statistics = $query->get();

        // Pré-chargement des utilisateurs en mémoire
        $allAgentUuids = $statistics->flatMap(function ($item) {
            return [
                $item->make_in_preparation_by,
                $item->make_delivered_by,
                $item->delivered_by,
                $item->make_rejected_by,
                $item->rejected_by,
                $item->make_cancel_for_new_update_by,
                $item->make_ready_by,
                $item->ready_by,
                $item->make_transferred_by,
                $item->transferred_by,
                $item->make_new_rejected_by,
                $item->rejected_after_validation_by,
            ];
        })->filter()->unique();

        $usersCache = \App\Models\User::whereIn('id', $allAgentUuids)->get()->keyBy('id');

        $getAgentName = function ($uuid) use ($usersCache) {
            if (!$uuid) return 'Non défini';
            return $usersCache->get($uuid)?->nom_utilisateur ?? ('Agent #' . $uuid);
        };

        $groupedByItem = $statistics->groupBy('order_menu_restaurant_item_uuid');

        $cookingItemDurations = [];
        $cookingItemDurationsPerAgent = [];
        $takeoverItemDurations = [];
        $takeoverItemDurationsPerAgent = [];
        $servingReadyItemDurations = [];
        $servingReadyItemDurationsPerAgent = [];
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

            // --- 1. FILTRES DE REJET ET MAL CONFECTION (Règles des ratios) ---
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
            $rejectAgentName = $getAgentName($rejectAgentUuid);

            $malConfectionAgentUuid = $malConfectionStat?->make_ready_by ?? $malConfectionStat?->ready_by ?? $malConfectionStat?->make_cancel_for_new_update_by;
            $malConfectionAgentName = $getAgentName($malConfectionAgentUuid);

            $rejectedQty = $rejectionStat ? (int) ($rejectionStat->quantity ?? 0) : 0;
            $malConfectionQty = $malConfectionStat ? (int) ($malConfectionStat->quantity ?? 0) : 0;

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

            if ($rejectionStat !== null || $malConfectionStat !== null || $stats->whereNotNull('deleted_at')->isNotEmpty()) {
                continue;
            }

            $transferredAt = $stats->whereNotNull('transferred_at')->pluck('transferred_at')->first();
            $inPreparationAt = $stats->whereNotNull('in_preparation_at')->pluck('in_preparation_at')->first();

            $readyStat = $stats->whereNotNull('ready_at')->sortByDesc('ready_at')->first();
            $readyAt = $readyStat?->ready_at;

            $deliveredStat = $stats->whereNotNull('delivered_at')->sortByDesc('delivered_at')->first();
            $deliveredAt = $deliveredStat?->delivered_at;

            $inPrepStat = $stats->whereNotNull('in_preparation_at')->first();
            $prepAgentUuid = $inPrepStat?->make_in_preparation_by;
            $prepAgentName = $getAgentName($prepAgentUuid);

            $readyAgentUuid = $readyStat?->make_ready_by ?? $readyStat?->ready_by;
            $readyAgentName = $getAgentName($readyAgentUuid);

            $deliveredAgentUuid = $deliveredStat?->make_delivered_by ?? $deliveredStat?->delivered_by;
            $deliveredAgentName = $getAgentName($deliveredAgentUuid);

            // 1. Durée de Cuisson
            if ($inPreparationAt && $readyAt) {
                $cookingDuration = Carbon::parse($inPreparationAt)->diffInSeconds(Carbon::parse($readyAt));

                $cookingItemDurations[] = [
                    'agent_uuid' => $prepAgentUuid,
                    'agent_name' => $prepAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => $cookingDuration,
                ];

                $cookingItemDurationsPerAgent[] = [
                    'agent_uuid' => $readyAgentUuid,
                    'agent_name' => $readyAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => $cookingDuration,
                ];
            }

            // 2. Durée de Prise en compte
            if ($transferredAt && $inPreparationAt) {
                $takeoverDuration = Carbon::parse($transferredAt)->diffInSeconds(Carbon::parse($inPreparationAt));

                $takeoverItemDurations[] = [
                    'agent_uuid' => $prepAgentUuid,
                    'agent_name' => $prepAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => $takeoverDuration,
                ];

                $takeoverItemDurationsPerAgent[] = [
                    'agent_uuid' => $prepAgentUuid,
                    'agent_name' => $prepAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => $takeoverDuration,
                ];
            }

            // 3. Durée pour servir un plat prêt
            if ($readyAt && $deliveredAt) {
                $servingDuration = Carbon::parse($readyAt)->diffInSeconds(Carbon::parse($deliveredAt));

                $servingReadyItemDurations[] = [
                    'agent_uuid' => $deliveredAgentUuid,
                    'agent_name' => $deliveredAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => $servingDuration,
                ];

                $servingReadyItemDurationsPerAgent[] = [
                    'agent_uuid' => $deliveredAgentUuid,
                    'agent_name' => $deliveredAgentName,
                    'dish_uuid' => $dishUuid,
                    'dish_name' => $dishName,
                    'quantity' => $orderedQuantity,
                    'duration_seconds' => $servingDuration,
                ];
            }

            // 4. Durée globale pour servir un plat
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
        
        $applyFilters = function ($items) use ($agentId, $menuUuid) {
            $collection = collect($items);

            if ($agentId) {
                $collection = $collection->filter(fn($item) => $item['agent_uuid'] == $agentId);
            }

            if ($menuUuid) {
                $collection = $collection->filter(fn($item) => $item['dish_uuid'] == $menuUuid);
            }

            return $collection;
        };

        $calculateAverages = function ($items, $groupByKey) use ($formatDuration, $applyFilters) {
            $collection = $applyFilters($items);

            return $collection->groupBy($groupByKey)->map(function ($group) use ($formatDuration) {
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

        $calculateRatio = function ($items, $groupByKey, $quantityKey) use ($applyFilters) {
            $collection = $applyFilters($items);

            return $collection->groupBy($groupByKey)->map(function ($group) use ($quantityKey) {
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

        $reportData = [];

        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_COOKING_TIME_DISHES) {
            $reportData['average_cooking_per_dish'] = $calculateAverages($cookingItemDurations, 'dish_uuid');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_COOKING_TIME_PER_AGENT) {
            $reportData['average_cooking_per_dish_and_agent'] = $calculateAverages($cookingItemDurationsPerAgent, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid']);
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_TAKE_NOTE_TIME_DISHES) {
            $reportData['average_takeover_per_dish'] = $calculateAverages($takeoverItemDurations, 'dish_uuid');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_TAKE_NOTE_TIME_PER_AGENT) {
            $reportData['average_takeover_per_dish_and_agent'] = $calculateAverages($takeoverItemDurationsPerAgent, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid']);
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_SERVE_READY_TIME) {
            $reportData['average_serving_ready_per_dish'] = $calculateAverages($servingReadyItemDurations, 'dish_uuid');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_SERVE_READY_TIME_PER_AGENT) {
            $reportData['average_serving_ready_per_dish_and_agent'] = $calculateAverages($servingReadyItemDurationsPerAgent, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid']);
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::AVERAGE_GLOBAL_SERVE_TIME) {
            $reportData['average_total_delivery_per_dish'] = $calculateAverages($totalDeliveryItemDurations, 'dish_uuid');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::REJECTION_RATIO_PER_DISH) {
            $reportData['rejection_ratio_per_dish'] = $calculateRatio($rejectionData, 'dish_uuid', 'rejected_quantity');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::REJECTION_RATIO_PER_AGENT) {
            $reportData['rejection_ratio_per_dish_and_agent'] = $calculateRatio($rejectionData, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid'], 'rejected_quantity');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::BAD_PREPARATION_RATIO_PER_DISH) {
            $reportData['mal_confection_ratio_per_dish'] = $calculateRatio($malConfectionData, 'dish_uuid', 'mal_confection_quantity');
        }
        if ($reportType === RestaurantReportType::ALL || $reportType === RestaurantReportType::BAD_PREPARATION_RATIO_PER_AGENT) {
            $reportData['mal_confection_ratio_per_dish_and_agent'] = $calculateRatio($malConfectionData, fn($item) => ($item['agent_uuid'] ?? 'none') . '_' . $item['dish_uuid'], 'mal_confection_quantity');
        }

        return response()->json([
            'status' => 'success',
            'report_type' => $reportType->value,
            'report_label' => $reportType->label(),
            'period' => [
                'start_date' => $startDate->format('d-m-Y H:i:s'),
                'end_date' => $endDate->format('d-m-Y H:i:s'),
            ],
            'data' => $reportData
        ]);
    }
}

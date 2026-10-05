<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use App\Models\OrderMenuRestaurant;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OthersStatisticsController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->start_date
            ? Carbon::createFromFormat('d-m-Y', $request->start_date)->startOfDay()
            : now()->startOfDay();

        $endDate = $request->end_date
            ? Carbon::createFromFormat('d-m-Y', $request->end_date)->endOfDay()
            : now()->endOfDay();

        $ordersTicket = OrderMenuRestaurant::where('is_used_restaurant_rooms', true)
            ->where('type_clients_for_payment', '!=', TypeClientsForPaiment::FREE->value)
            ->where('status', MenuOrderStatus::FACTURATE)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items', 'drinks'])
            ->get();

        $ordersHosted = OrderMenuRestaurant::where('is_used_restaurant_rooms', true)
            ->where('type_clients_for_payment', '!=', TypeClientsForPaiment::FREE->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items', 'drinks'])
            ->get();

        $ordersNonHostedDiverse = OrderMenuRestaurant::where('is_used_restaurant_rooms', false)
            ->where('type_clients_for_payment', '!=', TypeClientsForPaiment::FREE->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items', 'drinks'])
            ->get();

        $ordersPartner = OrderMenuRestaurant::where('type_clients_for_payment', TypeClientsForPaiment::PARTNER->value)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items', 'drinks'])
            ->get();

        $allDates = $ordersTicket->pluck('created_at')
            ->concat($ordersHosted->pluck('created_at'))
            ->concat($ordersNonHostedDiverse->pluck('created_at'))
            ->concat($ordersPartner->pluck('created_at'))
            ->map->format('d-m-Y')
            ->unique()
            ->sort();

        $statistics = $allDates->map(function ($dateFormatted) use ($ordersTicket, $ordersHosted, $ordersNonHostedDiverse, $ordersPartner) {

            $groupTicket = $ordersTicket->filter(fn($o) => $o->created_at->format('d-m-Y') === $dateFormatted);
            $sumTicket = $groupTicket->sum(fn($o) => ($o->total_items ?? 0) + ($o->total_drinks ?? 0));
            $countTicket = $groupTicket->count();

            $groupHosted = $ordersHosted->filter(fn($o) => $o->created_at->format('d-m-Y') === $dateFormatted);
            $sumHosted = $groupHosted->sum(fn($o) => ($o->total_items ?? 0) + ($o->total_drinks ?? 0));
            $countHosted = $groupHosted->count();

            $groupNonHosted = $ordersNonHostedDiverse->filter(fn($o) => $o->created_at->format('d-m-Y') === $dateFormatted);
            $sumNonHosted = $groupNonHosted->sum(fn($o) => ($o->total_items ?? 0) + ($o->total_drinks ?? 0));
            $countNonHosted = $groupNonHosted->count();

            $groupPartner = $ordersPartner->filter(fn($o) => $o->created_at->format('d-m-Y') === $dateFormatted);
            $sumPartner = $groupPartner->sum(fn($o) => ($o->total_items ?? 0) + ($o->total_drinks ?? 0));
            $countPartner = $groupPartner->count();

            return [
                'date' => $dateFormatted,

                'cross_referenced_average_ticket' => [
                    'total_sum_prices' => (float) $sumTicket,
                    'total_orders' => (int) $countTicket,
                    'value' => $countTicket > 0 ? round($sumTicket / $countTicket, 2) : 0,
                ],

                'hosted_sales_average_price' => [
                    'total_sum_prices' => (float) $sumHosted,
                    'total_orders' => (int) $countHosted,
                    'value' => $countHosted > 0 ? round($sumHosted / $countHosted, 2) : 0,
                ],

                'non_hosted_diverse_average_price' => [
                    'total_sum_prices' => (float) $sumNonHosted,
                    'total_orders' => (int) $countNonHosted,
                    'value' => $countNonHosted > 0 ? round($sumNonHosted / $countNonHosted, 2) : 0,
                ],

                'partner_average_ticket' => [
                    'total_sum_prices' => (float) $sumPartner,
                    'total_orders' => (int) $countPartner,
                    'value' => $countPartner > 0 ? round($sumPartner / $countPartner, 2) : 0,
                ],
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }
}

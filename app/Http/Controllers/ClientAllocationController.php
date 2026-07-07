<?php

namespace App\Http\Controllers;

use App\Models\ClientAllocation;
use App\Models\FreeClientRestaurant;
use App\Models\OrderMenuRestaurant;
use App\Models\RefundHistory;
use App\Models\RestaurantPartner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ClientAllocationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = ClientAllocation::with(['updater'])->where('amount_allocated', '>', 0);


        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('source_type', 'like', "%{$search}%")
                    ->orWhere('source_uuid', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('amount_allocated_total', 'like', "%{$search}%")
                    ->orWhere('amount_allocated', 'like', "%{$search}%");
            });
        }

        $totalAmountAllocated = (float) (clone $query)->sum('amount_allocated');
        $allocations = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $allocations->items(),
            'current_page' => $allocations->currentPage(),
            'last_page'    => $allocations->lastPage(),
            'total'        => $allocations->total(),
            'total_amount_allocated' => $totalAmountAllocated,
        ]);


    }

    public function store(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'uuid' => 'required|uuid',
            'amount' => 'required|numeric|min:1'
        ]);

        return DB::transaction(function () use ($request, $auth) {

            $allocation = ClientAllocation::where('uuid', $request->uuid)->firstOrFail();

            $amount = $request->amount;

            if ($amount > $allocation->amount_allocated) {
                return response()->json([
                    'success' => false,
                    'message' => 'Montant supérieur au solde disponible'
                ], 400);
            }

            switch ($allocation->source_type) {

                case 'order':

                    $order = OrderMenuRestaurant::where('uuid', $allocation->source_uuid)->firstOrFail();

                    $order->amount_allocated -= $amount;
                    $order->updated_by = $auth->id;
                    $order->save();

                    break;

                case 'free_client':

                    $free = FreeClientRestaurant::where('uuid', $allocation->source_uuid)->firstOrFail();

                    $free->amount_allocated -= $amount;
                    $free->amount_allocated_total -= $amount;
                    $free->updated_by = $auth->id;
                    $free->save();

                    break;

                case 'partner':

                    $partner = RestaurantPartner::where('uuid', $allocation->source_uuid)->firstOrFail();

                    $partner->amount_allocated -= $amount;
                    $partner->amount_allocated_total -= $amount;
                    $partner->updated_by = $auth->id;
                    $partner->save();

                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Source inconnue'
                    ], 400);
            }


            $allocation->amount_allocated -= $amount;
            $allocation->updated_by = $auth->id;
            $allocation->save();


            RefundHistory::create([
                'client_allocation_uuid' => $allocation->uuid,
                'source_type' => $allocation->source_type,
                'source_uuid' => $allocation->source_uuid,
                'amount' => $amount,
                'created_by' => $auth->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Remboursement effectué avec succès',
                'data' => $allocation
            ]);
        });
    }

    public function refundAll(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 403);
        }

        return DB::transaction(function () use ($uuid, $auth) {

            $allocation = ClientAllocation::where('uuid', $uuid)->firstOrFail();

            $amount = $allocation->amount_allocated;

            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun solde à rembourser.'
                ], 400);
            }

            switch ($allocation->source_type) {

                case 'order':
                    $order = OrderMenuRestaurant::where('uuid', $allocation->source_uuid)->firstOrFail();
                    $order->amount_allocated -= $amount;
                    $order->updated_by = $auth->id;
                    $order->save();
                    break;

                case 'free_client':
                    $free = FreeClientRestaurant::where('uuid', $allocation->source_uuid)->firstOrFail();
                    $free->amount_allocated -= $amount;
                    $free->amount_allocated_total -= $amount;
                    $free->updated_by = $auth->id;
                    $free->save();
                    break;

                case 'partner':
                    $partner = RestaurantPartner::where('uuid', $allocation->source_uuid)->firstOrFail();
                    $partner->amount_allocated -= $amount;
                    $partner->amount_allocated_total -= $amount;
                    $partner->updated_by = $auth->id;
                    $partner->save();
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Source inconnue.'
                    ], 400);
            }

            $allocation->amount_allocated = 0;
            $allocation->updated_by = $auth->id;
            $allocation->save();

            RefundHistory::create([
                'client_allocation_uuid' => $allocation->uuid,
                'source_type'            => $allocation->source_type,
                'source_uuid'            => $allocation->source_uuid,
                'amount'                 => $amount,
                'created_by'             => $auth->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Le remboursement intégral a été effectué avec succès.',
                'data'    => $allocation->fresh(),
            ]);
        });
    }
}

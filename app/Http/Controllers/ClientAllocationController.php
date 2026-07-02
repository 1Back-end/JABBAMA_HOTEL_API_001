<?php

namespace App\Http\Controllers;

use App\Models\ClientAllocation;
use Illuminate\Http\Request;

class ClientAllocationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = ClientAllocation::query();


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
}

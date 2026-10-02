<?php

namespace App\Http\Controllers;

use App\Enums\TableCategoryEnum;
use App\Models\DatabaseTableMetadata;
use Illuminate\Http\Request;

class DatabaseTableMetadataController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = DatabaseTableMetadata::query();

        $query->where('category', '!=', TableCategoryEnum::UNCATEGORIZED->value);

        if ($request->has('category') && !empty($request->category)) {
            $query->where('category', $request->category);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('table_name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $metadata = $query->orderBy('category', 'asc')->get();

        $groupedData = $metadata->groupBy('category')->map(function ($items, $categoryKey) {
            $enumCase = TableCategoryEnum::tryFrom($categoryKey);
            $label = $enumCase ? $enumCase->label() : ucfirst($categoryKey);

            return [
                'category_key' => $categoryKey,
                'category_label' => $label,
                'tables' => $items->values()
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $groupedData
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

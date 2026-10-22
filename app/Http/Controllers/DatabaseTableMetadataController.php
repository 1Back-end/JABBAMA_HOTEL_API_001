<?php

namespace App\Http\Controllers;

use App\Enums\TableCategoryEnum;
use App\Models\DatabaseTableMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

        $metadata = $query->get();
        $categoryOrder = [
            TableCategoryEnum::STOCK->value,
            TableCategoryEnum::CONFIGURATION->value,
            TableCategoryEnum::SYSTEM->value,
        ];

        $groupedData = $metadata->groupBy('category')->map(function ($items, $categoryKey) use ($categoryOrder) {
            $enumCase = TableCategoryEnum::tryFrom($categoryKey);
            $label = $enumCase ? $enumCase->label() : ucfirst($categoryKey);

            $priority = array_search($categoryKey, $categoryOrder);
            if ($priority === false) {
                $priority = 999;
            }

            return [
                'priority'       => $priority,
                'category_key'   => $categoryKey,
                'category_label' => $label,
                'tables'         => $items->values()
            ];
        })
            ->sortBy('priority')
            ->map(function ($group) {
                unset($group['priority']);
                return $group;
            })
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $groupedData
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
            'tables'   => ['nullable', 'array'],
        ]);

        $user = $request->user();
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect. Action non autorisée.',
            ], 403);
        }

        $tableUuids = $request->input('tables', []);

        $query = DatabaseTableMetadata::query();

        if (!empty($tableUuids)) {
            $query->whereIn('uuid', $tableUuids);
        }

        $selectedTables = $query->pluck('table_name')->toArray();

        if (empty($selectedTables)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune table valide sélectionnée pour la réinitialisation.',
            ], 400);
        }

        try {
            $allTablesToProcess = $selectedTables;
            $databaseName = DB::getDatabaseName();

            $added = true;
            while ($added) {
                $added = false;

                $childTables = DB::table('information_schema.key_column_usage')
                    ->select(DB::raw('TABLE_NAME as table_name'))
                    ->whereIn(DB::raw('REFERENCED_TABLE_NAME'), $allTablesToProcess)
                    ->where('TABLE_SCHEMA', $databaseName)
                    ->whereNotNull('REFERENCED_TABLE_NAME')
                    ->distinct()
                    ->pluck('table_name')
                    ->toArray();

                foreach ($childTables as $child) {
                    if (!in_array($child, $allTablesToProcess)) {
                        $allTablesToProcess[] = $child;
                        $added = true;
                    }
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            DB::beginTransaction();

            $sensitiveTables = DatabaseTableMetadata::where('is_sensitive_data', true)
                ->pluck('table_name')
                ->toArray();

            $allTablesToProcess = array_reverse(array_unique($allTablesToProcess));

            foreach ($allTablesToProcess as $tableName) {
                if (in_array($tableName, ['migrations', 'database_table_metadata', 'database_tables_metadata'])) {
                    continue;
                }

                if (Schema::hasTable($tableName)) {
                    if ($tableName === 'warehouses') {
                        if (Schema::hasTable('produit_point')) {
                            DB::table('produit_point')->update(['point_uuid' => null]);
                        }
                        DB::table($tableName)->delete();
                    }
                    elseif (in_array($tableName, $sensitiveTables)) {
                        DB::table($tableName)->update([
                            'quantity'       => 0,
                            'stocks_minimal' => 0,
                        ]);
                    }
                    else {
                        DB::table($tableName)->delete();
                    }
                }
            }

            DB::commit();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'success' => true,
                'message' => 'Les tables sélectionnées et leurs dépendances ont été réinitialisées avec succès.',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du traitement : ' . $e->getMessage(),
            ], 500);
        }
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

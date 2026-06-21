<?php

namespace App\Http\Controllers;

use App\Models\CashReceiptType;
use App\Models\RestaurantExpenseFamily;
use App\Models\RestaurantExpenseType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @permission_category Gestion des catégories de dépenses
 * @permission_module Gestion du restaurant
 */
class RestaurantExpenseTypeController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::index
     * @permission_desc Afficher la liste des catégories de dépenses
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = RestaurantExpenseType::with([
            'creator',
            'updater',
        ]);

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }
        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::store
     * @permission_desc Créer une catégorie de dépenses
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:restaurant_expense_types,name'],
            'is_linked_to_activity' => ['nullable', 'boolean'],
        ]);

        try {
            $type = RestaurantExpenseType::create([
                'code' => Str::slug($validated['name'], '_'),
                'name' => $validated['name'],
                'created_by' => auth()->id(),
                'is_linked_to_activity' => $validated['is_linked_to_activity'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Type de dépense créé avec succès.',
                'data' => $type
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::update
     * @permission_desc Modifier une catégorie de dépenses
     */
    public function update(Request $request, string $uuid)
    {
        $type = RestaurantExpenseType::where('uuid', $uuid)->first();

        if (!$type) {
            return response()->json([
                'status' => 'error',
                'message' => 'Type introuvable.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:restaurant_expense_types,name,' . $type->uuid . ',uuid'],
            'is_linked_to_activity' => ['nullable', 'boolean'],
        ]);

        $type->update([
            'code' => Str::slug($validated['name'], '_'),
            'name' => $validated['name'],
            'updated_by' => auth()->id(),
            'is_linked_to_activity' => $validated['is_linked_to_activity'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Type de dépense mis à jour avec succès.',
            'data' => $type
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::updateStatus
     * @permission_desc Activer/Désactiver une catégorie de dépenses
     */
    public function updateStatus(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $type = RestaurantExpenseType::where('uuid', $uuid)->first();
        $type->is_active = $request->is_active;
        $type->updated_by = $auth->id;
        $type->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::storeSubFamily
     * @permission_desc Créer les sous catégories de dépenses
     */
    public function storeSubFamily(Request $request)
    {
        $validated = $request->validate([
            'restaurant_expense_uuid' => [
                'required',
                'uuid',
                'exists:restaurant_expense_types,uuid'
            ],

            'sub_families' => ['required', 'array', 'min:1'],
        ]);

        DB::beginTransaction();

        try {

            $userId = auth()->id();
            $expenseUuid = $validated['restaurant_expense_uuid'];

            $saveTree = function (
                array $items,
                ?string $parentUuid = null,
                int $level = 1
            ) use (&$saveTree, $expenseUuid, $userId) {

                foreach ($items as $item) {

                    $name = trim($item['name']);

                    $exists = RestaurantExpenseFamily::where(
                        'restaurant_expense_uuid',
                        $expenseUuid
                    )
                        ->where('parent_uuid', $parentUuid)
                        ->whereRaw('LOWER(name)=?', [strtolower($name)])
                        ->where('is_used', true)
                        ->exists();

                    if ($exists) {
                        throw new \Exception(
                            "La catégorie '{$name}' existe déjà."
                        );
                    }

                    $family = RestaurantExpenseFamily::create([

                        'restaurant_expense_uuid' => $expenseUuid,

                        'parent_uuid' => $parentUuid,

                        'name' => strtoupper($name),

                        'code' => Str::slug($name, '_') . '_' . Str::random(6),

                        'description' => $item['description'] ?? null,

                        'indexation' =>
                            $level === 1
                                ? ($item['indexation'] ?? null)
                                : null,

                        'level' => $level,

                        'is_used' => true,
                        'is_active' => true,

                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);

                    if (
                        !empty($item['children']) &&
                        is_array($item['children'])
                    ) {
                        $saveTree(
                            $item['children'],
                            $family->uuid,
                            $level + 1
                        );
                    }
                }
            };

            $saveTree($validated['sub_families']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Sous-catégories enregistrées avec succès',
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::Update_Sub_Family
     * @permission_desc Modifier les sous catégories de dépenses
     */
    public function Update_Sub_Family(Request $request)
    {
        $validated = $request->validate([
            'restaurant_expense_uuid' => [
                'required',
                'uuid',
                'exists:restaurant_expense_types,uuid'
            ],
            'sub_families' => ['required', 'array', 'min:1'],

            'sub_families.*.uuid' => ['nullable', 'uuid', 'exists:restaurant_expense_types_families,uuid'],
            'sub_families.*.name' => ['required', 'string', 'max:255'],
            'sub_families.*.description' => ['nullable', 'string'],
            'sub_families.*.indexation' => ['nullable', 'string'],
            'sub_families.*.children' => ['nullable', 'array'],
        ]);

        DB::beginTransaction();

        try {
            $userId = auth()->id();
            $expenseUuid = $validated['restaurant_expense_uuid'];
            $keptUuids = [];

            $updateTree = function (
                array $items,
                ?string $parentUuid = null,
                int $level = 1
            ) use (&$updateTree, $expenseUuid, $userId, &$keptUuids) {

                foreach ($items as $item) {
                    if (!is_array($item) || empty($item['name'])) {
                        continue;
                    }

                    $name = trim($item['name']);
                    $uuid = $item['uuid'] ?? null;
                    $model = null;

                    if ($uuid) {
                        $model = RestaurantExpenseFamily::where('uuid', $uuid)->first();
                    } else {

                        $model = RestaurantExpenseFamily::where('restaurant_expense_uuid', $expenseUuid)
                            ->where('parent_uuid', $parentUuid)
                            ->where('is_used', true)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                            ->first();

                        if ($model) {
                            $uuid = $model->uuid;
                        }
                    }

                    if ($model) {
                        // Mode UPDATE (Existant ou retrouvé)
                        $model->update([
                            'restaurant_expense_uuid' => $expenseUuid,
                            'parent_uuid' => $parentUuid,
                            'name' => strtoupper($name),
                            'code' => Str::slug($name, '_') . '_' . substr($uuid, 0, 6),
                            'description' => $item['description'] ?? null,
                            'indexation' => $level === 1 ? ($item['indexation'] ?? null) : null,
                            'level' => $level,
                            'updated_by' => $userId,
                        ]);
                    } else {
                        $generatedUuid = (string) Str::uuid();
                        $model = RestaurantExpenseFamily::create([
                            'uuid' => $generatedUuid,
                            'restaurant_expense_uuid' => $expenseUuid,
                            'parent_uuid' => $parentUuid,
                            'name' => strtoupper($name),
                            'code' => Str::slug($name, '_') . '_' . Str::random(6),
                            'description' => $item['description'] ?? null,
                            'indexation' => $level === 1 ? ($item['indexation'] ?? null) : null,
                            'level' => $level,
                            'is_used' => true,
                            'is_active' => true,
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ]);
                    }

                    $keptUuids[] = $model->uuid;

                    if (!empty($item['children']) && is_array($item['children'])) {
                        $updateTree($item['children'], $model->uuid, $level + 1);
                    }
                }
            };

            $updateTree($validated['sub_families']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mise à jour effectuée avec succès',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildHierarchy($items, $parentId = null)
    {
        return $items
            ->where('parent_uuid', $parentId)
            ->where('is_used', true)
            ->map(function ($item) use ($items) {

                $item->children = $this->buildHierarchy($items, $item->uuid);

                return $item;
            })
            ->sortBy('level')
            ->values();
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::get_all_sub_families
     * @permission_desc Afficher la liste des sous catégories de dépenses
     */
    public function get_all_sub_families(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = RestaurantExpenseFamily::with([
            'type',
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'childrenRecursive'
        ])
            ->whereNull('parent_uuid')
            ->where('is_used', true);

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('indexation', 'like', "%{$search}%");
            });
        }

        $paginated = $query
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        $groupedData = collect($paginated->items())
            ->groupBy('restaurant_expense_uuid')
            ->map(function ($families) {

                $first = $families->first();

                return [
                    'type' => $first->type,
                    'restaurant_expense' => $first->type,
                    'families' => $families->values(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $groupedData,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::updateStatusFamilyAndSubFamily
     * @permission_desc Activer/Désactiver les sous catégories de dépenses
     */
    public function updateStatusFamilyAndSubFamily(Request $request, $uuid)
    {
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $type = RestaurantExpenseFamily::where('uuid', $uuid)->first();
        $type->is_active = $request->is_active;
        $type->updated_by = $auth->id;
        $type->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
    }


    public function toggleStatus(string $uuid)
    {
        DB::beginTransaction();

        try {

            $userId = auth()->id();

            $family = RestaurantExpenseFamily::where('uuid', $uuid)->firstOrFail();

            $newStatus = !$family->is_used;

            $this->toggleChildren($family->uuid, $newStatus, $userId);

            // 🔥 update parent lui-même
            $family->update([
                'is_used' => $newStatus,
                'updated_by' => $userId,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Statut mis à jour avec succès',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function toggleChildren($parentUuid, $status, $userId)
    {
        $children = RestaurantExpenseFamily::where('parent_uuid', $parentUuid)->get();

        foreach ($children as $child) {

            $child->update([
                'is_used' => $status,
                'updated_by' => $userId,
            ]);

            // recursion
            $this->toggleChildren($child->uuid, $status, $userId);
        }
    }

}

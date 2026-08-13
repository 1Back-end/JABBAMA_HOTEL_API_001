<?php

namespace App\Http\Controllers;

use App\Models\CashReceiptFamily;
use App\Models\CashReceiptType;
use App\Models\Category;
use App\Models\SubCashCollectionFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;


/**
 * @permission_category Gestion des catégories d'encaissements
 * @permission_module Gestion du restaurant
 */

class CashReceiptTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission CashReceiptTypeController::index
     * @permission_desc Afficher la liste des catégories d'encaissements
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = CashReceiptType::with([
            'creator',
            'updater',
            'families'
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
     * @permission CashReceiptTypeController::store
     * @permission_desc Créer les catégories d'encaissements
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:cash_receipt_types,name'],
            'is_linked_to_turnover' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $type = CashReceiptType::create([
                'code' => Str::slug($validated['name'], '_'),
                'name' => $validated['name'],
                'is_linked_to_turnover' => $validated['is_linked_to_turnover'],
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Type d\'encaissement créé avec succès.',
                'data' => $type
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();
            Log::error('Erreur création CashReceiptType', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création.'
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission CashReceiptTypeController::update
     * @permission_desc Modifier les catégories d'encaissements
     */
    public function update(Request $request, string $uuid)
    {
        $type = CashReceiptType::find($uuid);

        if (!$type) {
            return response()->json([
                'status' => 'error',
                'message' => 'Type introuvable.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string',  Rule::unique('cash_receipt_types', 'name')->ignore($uuid, 'uuid')],
            'is_linked_to_turnover' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $type->update([
                'code' => Str::slug($validated['name'], '_'),
                'name' => $validated['name'],
                'is_linked_to_turnover' => $validated['is_linked_to_turnover'],
                'updated_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Type d\'encaissement modifié avec succès.',
                'data' => $type->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la modification.'
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission CashReceiptTypeController::show
     * @permission_desc Afficher les détails d'une catégorie d'encaissement
     */
    public function show(string $uuid)
    {
        $type = CashReceiptType::find($uuid);

        if (!$type) {
            return response()->json([
                'status' => 'error',
                'message' => 'Type introuvable.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $type
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission CashReceiptTypeController::updateStatus
     * @permission_desc Acriver/Désactiver les catégories d'encaissements
     */
    public function updateStatus(Request $request, string $uuid)
    {
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $type = CashReceiptType::where('uuid', $uuid)->first();
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
     * @permission CashReceiptTypeController::store_family
     * @permission_desc Créer une sous catégorie d'encaissement pour les activités liées au restaurant
     */
    public function store_family(Request $request)
    {
        $validated = $request->validate([
            'cash_receipt_type_uuid' => ['required', 'uuid', 'exists:cash_receipt_types,uuid'],
            'families' => ['required', 'array', 'min:1'],
            'families.*.name' => ['required', 'string', 'max:255'],
            'families.*.indexation' => ['required'],
        ]);

        DB::beginTransaction();

        try {

            $createdBy = auth()->id();
            $typeUuid = $validated['cash_receipt_type_uuid'];

            $incomingNames = collect($validated['families'])
                ->map(fn($f) => strtolower(trim($f['name'])))
                ->values();

            $existing = CashReceiptFamily::where('cash_receipt_type_uuid', $typeUuid)
                ->where('is_family', true)
                ->whereIn(DB::raw('LOWER(name)'), $incomingNames)
                ->pluck('name')
                ->map(fn($n) => strtoupper($n))
                ->toArray();

            // ❌ SI DOUBLONS → STOP AVANT INSERT
            if (!empty($existing)) {
                DB::rollBack();

                return response()->json([
                    'status' => 'warning',
                    'message' => 'Certaines familles existent déjà',
                    'data' => $existing
                ], 409);
            }

            // ✅ INSERT SEULEMENT SI TOUT EST OK
            foreach ($validated['families'] as $family) {

                $name = strtoupper(trim($family['name']));
                $baseCode = Str::slug($name, '_');

                CashReceiptFamily::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'code' => $baseCode,
                    'indexation' => $family['indexation'],
                    'cash_receipt_type_uuid' => $typeUuid,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                    'is_family' => true,
                    'is_sub_family' => false,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Familles enregistrées avec succès',
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
     * @permission CashReceiptTypeController::update_family
     * @permission_desc Modifier une sous catégorie d'encaissement pour les activités liées au restaurant
     */
    public function update_family(Request $request)
    {
        $validated = $request->validate([
            'cash_receipt_type_uuid' => ['required', 'uuid', 'exists:cash_receipt_types,uuid'],
            'families' => ['required', 'array', 'min:1'],

            'families.*.uuid' => ['nullable', 'uuid', 'exists:cash_receipt_families,uuid'],
            'families.*.name' => ['required', 'string', 'max:255'],
            'families.*.indexation' => ['required', 'string'],
        ]);

        DB::beginTransaction();

        try {

            $userId = auth()->id();
            $typeUuid = $validated['cash_receipt_type_uuid'];

            $keptUuids = [];

            foreach ($validated['families'] as $family) {

                $uuid = $family['uuid'] ?? null;
                $name = strtoupper(trim($family['name']));


                $exists = CashReceiptFamily::where('cash_receipt_type_uuid', $typeUuid)
                    ->where('is_family', true)
                    ->whereRaw('LOWER(name) = ?', [strtolower($family['name'])])
                    ->when($uuid, fn($q) => $q->where('uuid', '!=', $uuid))
                    ->exists();

                if ($exists) {
                    throw new \Exception("La famille '{$family['name']}' existe déjà.");
                }

                if ($uuid) {

                    $model = CashReceiptFamily::where('uuid', $uuid)->first();

                    if (!$model) {
                        throw new \Exception("Famille introuvable: {$uuid}");
                    }

                    $model->update([
                        'name' => $name,
                        'code' => Str::slug($name, '_') . '_' . substr($uuid ?? Str::uuid(), 0, 8),
                        'indexation' => $family['indexation'],
                        'cash_receipt_type_uuid' => $typeUuid,
                        'updated_by' => $userId,
                    ]);

                } else {

                    $model = CashReceiptFamily::create([
                        'uuid' => (string) Str::uuid(),
                        'name' => $name,
                        'code' => Str::slug($name, '_') . '_' . substr($uuid ?? Str::uuid(), 0, 8),
                        'indexation' => $family['indexation'],
                        'cash_receipt_type_uuid' => $typeUuid,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                        'is_family' => true,
                        'is_sub_family' => false,
                        'is_used' => true,
                    ]);
                }

                $keptUuids[] = $model->uuid;
            }


            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Familles mises à jour avec succès',
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
     * @permission CashReceiptTypeController::store_sub_family
     * @permission_desc Créer une sous catégorie d'encaissement pour les activités non liées au restaurant
     */
    public function store_sub_family(Request $request)
    {
        $validated = $request->validate([
            'cash_receipt_type_uuid' => ['required', 'uuid', 'exists:cash_receipt_types,uuid'],
            'sub_families' => ['required', 'array', 'min:1'],

            'sub_families.*.name' => ['required', 'string', 'max:255'],
            'sub_families.*.description' => ['nullable', 'string'],
            'sub_families.*.children' => ['nullable', 'array'],
        ]);

        DB::beginTransaction();

        try {

            $createdBy = auth()->id();
            $typeUuid = $validated['cash_receipt_type_uuid'];

            $insertTree = function ($items, $parentUuid = null, $level = 1) use (&$insertTree, $createdBy, $typeUuid) {

                foreach ($items as $item) {

                    $name = trim($item['name']);

                    $exists = CashReceiptFamily::where('cash_receipt_type_uuid', $typeUuid)
                        ->where('parent_uuid', $parentUuid)
                        ->where('is_used', true)
                        ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                        ->exists();

                    if ($exists) {
                        throw new \Exception("La catégorie '{$name}' existe déjà");
                    }

                    $model = CashReceiptFamily::create([
                        'uuid' => (string) Str::uuid(),
                        'cash_receipt_type_uuid' => $typeUuid,
                        'parent_uuid' => $parentUuid,
                        'level' => $level,
                        'name' => $name,
                        'code' => Str::slug($name, '_') . '_' . substr($uuid ?? Str::uuid(), 0, 8),
                        'description' => $item['description'] ?? null,
                        'created_by' => $createdBy,
                        'updated_by' => $createdBy,
                        'is_family' => false,
                        'is_sub_family' => true,
                    ]);

                    if (!empty($item['children'])) {
                        $insertTree($item['children'], $model->uuid, $level + 1);
                    }
                }
            };

            $insertTree($validated['sub_families']);

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
     * @permission CashReceiptTypeController::Update_Sub_Family
     * @permission_desc Modifier une sous catégorie d'encaissement pour les activités non liées au restaurant
     */
    public function Update_Sub_Family(Request $request)
    {
        $validated = $request->validate([
            'cash_receipt_type_uuid' => ['required', 'uuid', 'exists:cash_receipt_types,uuid'],
            'sub_families' => ['required', 'array', 'min:1'],

            'sub_families.*.uuid' => ['nullable', 'uuid', 'exists:cash_receipt_families,uuid'],
            'sub_families.*.name' => ['required', 'string', 'max:255'],
            'sub_families.*.description' => ['nullable', 'string'],
            'sub_families.*.children' => ['nullable', 'array'],
        ]);

        DB::beginTransaction();

        try {
            $userId = auth()->id();
            $typeUuid = $validated['cash_receipt_type_uuid'];
            $keptUuids = [];

            $updateTree = function ($items, $parentUuid = null, $level = 1)
            use (&$updateTree, $userId, $typeUuid, &$keptUuids) {

                foreach ($items as $item) {
                    if (!is_array($item) || empty($item['name'])) {
                        continue;
                    }

                    $name = trim($item['name']);
                    $uuid = $item['uuid'] ?? null;
                    $model = null;

                    // On cherche d'abord par UUID ou par Nom + Parent
                    if ($uuid) {
                        $model = CashReceiptFamily::where('uuid', $uuid)->first();
                    } else {
                        $model = CashReceiptFamily::where('cash_receipt_type_uuid', $typeUuid)
                            ->where('parent_uuid', $parentUuid)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                            ->first();

                        if ($model) {
                            $uuid = $model->uuid;
                        }
                    }

                    // ENREGISTREMENT (UPDATE OU CREATE) DIRECT SANS AUCUN BLOCAGE
                    if ($model) {
                        $model->update([
                            'cash_receipt_type_uuid' => $typeUuid,
                            'parent_uuid' => $parentUuid,
                            'level' => $level,
                            'name' => $name,
                            'code' => Str::slug($name, '_') . '_' . substr($uuid, 0, 8),
                            'description' => $item['description'] ?? null,
                            'is_used' => true,
                            'updated_by' => $userId,
                        ]);
                    } else {
                        $generatedUuid = (string) Str::uuid();
                        $model = CashReceiptFamily::create([
                            'uuid' => $generatedUuid,
                            'cash_receipt_type_uuid' => $typeUuid,
                            'parent_uuid' => $parentUuid,
                            'level' => $level,
                            'name' => $name,
                            'code' => Str::slug($name, '_') . '_' . substr($generatedUuid, 0, 8),
                            'description' => $item['description'] ?? null,
                            'is_family' => false,
                            'is_sub_family' => true,
                            'is_used' => true,
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

            // START TREE
            $updateTree($validated['sub_families']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Arbre mis à jour correctement',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('UPDATE FAILED', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggleStatus(string $uuid)
    {
        DB::beginTransaction();

        try {

            $userId = auth()->id();

            $family = CashReceiptFamily::where('uuid', $uuid)->firstOrFail();

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
        $children = CashReceiptFamily::where('parent_uuid', $parentUuid)->get();

        foreach ($children as $child) {

            $child->update([
                'is_used' => $status,
                'updated_by' => $userId,
            ]);

            // recursion
            $this->toggleChildren($child->uuid, $status, $userId);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission CashReceiptTypeController::updateStatusFamilyAndSubFamily
     * @permission_desc Activer/Désactiver sous catégorie d'encaissement
     */
    public function updateStatusFamilyAndSubFamily(Request $request, $uuid)
    {
        $auth = auth()->user();
        $request->validate([
            'is_active' => 'required|boolean',
        ],[
            'is_active.required' => 'Le statut est obligatoire.',
        ]);
        $type = CashReceiptFamily::where('uuid', $uuid)->first();
        $type->is_active = $request->is_active;
        $type->updated_by = $auth->id;
        $type->save();
        return response()->json([
            'success' => true,
            "message" => "Statut modifié avec succès"
        ]);
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
     * @permission CashReceiptTypeController::get_all_families_and_sub_families
     * @permission_desc Afficher la liste des sous catégories d'encaissements
     */
    public function get_all_families_and_sub_families(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = CashReceiptFamily::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'cashReceiptType'
        ]);

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('indexation', 'like', "%{$search}%");
            });
        }

        $families = $query->latest()->get();

        // 🔥 GROUP BY TYPE
        $grouped = $families->groupBy(fn($item) => $item->cashReceiptType->uuid);

        $result = $grouped->map(function ($items) {

            $items = $items->values();
            $subFamiliesCollection = $items->filter(fn($i) => $i->is_sub_family == 1)->values();

            return [
                'cash_receipt_type' => $items->first()->cashReceiptType,

                'families' => $items
                    ->filter(fn($i) => $i->is_family == 1)
                    ->values(),

                'sub_families' => $this->buildHierarchy($subFamiliesCollection, null),
            ];
        })->values();

        $paginated = $result->forPage($page, $perPage);

        return response()->json([
            'data'         => $paginated->values(),
            'current_page' => $page,
            'last_page'    => ceil($result->count() / $perPage),
            'total'        => $result->count(),
        ]);
    }

    public function getFamiliesGrouped()
    {
        $families = CashReceiptFamily::where('is_used', true)
            ->whereNull('indexation')
            ->whereNull('parent_uuid')
            ->with(['childrenRecursive' => function ($query) {
                $query->where('is_used', true)
                    ->whereNull('indexation');
            }])
            ->orderBy('level')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $families,
        ]);
    }




}

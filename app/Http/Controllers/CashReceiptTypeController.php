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
        ]);

        DB::beginTransaction();

        try {

            $createdBy = auth()->id();
            $typeUuid = $validated['cash_receipt_type_uuid'];

            $incomingNames = collect($validated['sub_families'])
                ->map(fn($s) => strtolower(trim($s['name'])))
                ->values();

            // 🔴 EXISTANTS EN BASE
            $existing = CashReceiptFamily::where('cash_receipt_type_uuid', $typeUuid)
                ->where('is_sub_family', true)
                ->whereIn(DB::raw('LOWER(name)'), $incomingNames)
                ->pluck('name')
                ->map(fn($n) => strtoupper($n))
                ->toArray();

            // ❌ STOP SI DOUBLONS
            if (!empty($existing)) {
                DB::rollBack();

                return response()->json([
                    'status' => 'warning',
                    'message' => 'Certaines sous-familles existent déjà',
                    'data' => $existing
                ], 409);
            }

            // ✅ INSERT
            foreach ($validated['sub_families'] as $sub) {

                $name = trim($sub['name']);
                $baseCode = Str::slug($name, '_');

                CashReceiptFamily::create([
                    'uuid' => (string) Str::uuid(),
                    'cash_receipt_type_uuid' => $typeUuid,
                    'name' => $name,
                    'code' => $baseCode,
                    'description' => $sub['description'] ?? null,
                    'is_family' => false,
                    'is_sub_family' => true,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Sous-familles enregistrées avec succès',
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
            'creator',
            'updater',
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

            return [
                'cash_receipt_type' => $items->first()->cashReceiptType,
                'families' => $items->filter(fn($i) => $i->is_family == 1)->values(),
                'sub_families' => $items->filter(fn($i) => $i->is_sub_family == 1)->values(),
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




}

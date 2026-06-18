<?php

namespace App\Http\Controllers;

use App\Models\CashReceiptFamily;
use App\Models\CashReceiptType;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;


/**
 * @permission_category Gestion des types d'encaissements
 * @permission_module Gestion du restaurant
 */

class CashReceiptTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission CashReceiptTypeController::index
     * @permission_desc Afficher la liste des types d'encaissements
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
     * @permission_desc Créer les types d'encaissements
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
     * @permission_desc Modifier les types d'encaissements
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
     * @permission_desc Afficher les détails d'un type d'encaissement
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
     * @permission_desc Acriver/Désactiver les types d'encaissements
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
     * @permission_desc Créer une famille d'encaissement
     */
    public function store_family(Request $request)
    {
        $validated = $request->validate([
            'cash_receipt_type_uuid' => ['required', 'uuid', 'exists:cash_receipt_types,uuid'],
            'families' => ['required', 'array', 'min:1'],
            'families.*.name' => ['required', 'string', 'max:255'],
        ]);

        DB::beginTransaction();

        try {

            $createdBy = auth()->id();
            $typeUuid = $validated['cash_receipt_type_uuid'];

            // 🔥 1. SUPPRESSION FORCÉE (IMPORTANT)
            CashReceiptFamily::where('cash_receipt_type_uuid', $typeUuid)->delete();

            // 🔥 OPTION BONUS : sécurité anti doublon global (très important)
            DB::table('cash_receipt_families')
                ->where('cash_receipt_type_uuid', $typeUuid)
                ->delete();

            $data = [];

            foreach ($validated['families'] as $family) {

                $name = strtoupper(trim($family['name']));
                $baseCode = Str::slug($name, '_');

                $data[] = [
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'code' => $baseCode,
                    'cash_receipt_type_uuid' => $typeUuid,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // 🔥 2. INSERT SAFE
            if (!empty($data)) {
                DB::table('cash_receipt_families')->insert($data);
            }

            // 🔥 3. UPDATE PARENT
            CashReceiptType::where('uuid', $typeUuid)
                ->update([
                    'have_family' => true,
                    'updated_by' => $createdBy,
                    'updated_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Sync complète réussie',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

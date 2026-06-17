<?php

namespace App\Http\Controllers;

use App\Models\CashReceiptType;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'code' => ['required', 'string','unique:cash_receipt_types,code'],
            'name' => ['required', 'string', 'unique:cash_receipt_types,name'],
            'is_linked_to_turnover' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $type = CashReceiptType::create([
                'code' => strtoupper($validated['code']),
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
            'code' => ['required', 'string',Rule::unique('cash_receipt_types', 'code')->ignore($uuid, 'uuid')],
            'name' => ['required', 'string',  Rule::unique('cash_receipt_types', 'name')->ignore($uuid, 'uuid')],
            'is_linked_to_turnover' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $type->update([
                'code' => strtoupper($validated['code']),
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
}

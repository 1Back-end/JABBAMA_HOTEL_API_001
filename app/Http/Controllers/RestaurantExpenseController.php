<?php

namespace App\Http\Controllers;

use App\Models\ExpensePayment;
use App\Models\PaymentRegulation;
use App\Models\RestaurantExpenseDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
/**
 * @permission_category Gestion des dépenses
 * @permission_module Gestion du restaurant
 */
class RestaurantExpenseController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseController::index
     * @permission_desc Afficher la liste des dépenses
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = ExpensePayment::with([
            'creator',
            'updater',
            'expenseType',
            'family',
            'method'
        ]);

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
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
     * @permission RestaurantExpenseController::show
     * @permission_desc Afficher les détails d'une dépense
     */
    public function show(string $uuid)
    {
        $query = ExpensePayment::with([
            'creator',
            'updater',
            'expenseType',
            'family',
            'method'
        ])->findOrFail($uuid);

        if (!$query) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dépenses introuvable.',
                ''
            ], 404);
        }
        return response()->json([
            'status' => 'success',
            'data' => $query,
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseController::store
     * @permission_desc Créer une dépense
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'restaurant_expense_type_uuid'   => 'required|uuid',
            'restaurant_expense_family_uuid' => 'nullable|uuid',
            'payment_method_uuid'            => 'required|uuid',
            'family_hierarchy_uuids'         => 'nullable|array',
            'family_hierarchy_uuids.*'       => 'uuid',
            'name'                           => 'required|string|max:255',
            'amount'                         => 'required|numeric|min:0',
            'description'                    => 'nullable|string|max:1000',
            'date'                           => 'nullable|date',
        ]);
        $createdAt = $request->filled('date')
            ? Carbon::parse($request->date)->setTimeFrom(Carbon::now())
            : Carbon::now();

        DB::beginTransaction();

        try {

            $expense = ExpensePayment::create([
                'restaurant_expense_type_uuid'   => $request->restaurant_expense_type_uuid,
                'restaurant_expense_family_uuid' => $request->restaurant_expense_family_uuid,
                'regulation_method_uuid'         => $request->payment_method_uuid,
                'hierarchy_uuids'                => $request->family_hierarchy_uuids ?? [],
                'amount'                         => $request->amount,
                'name'                           => $request->name,
                'description'                    => $request->description,
                'paid_at'                        => $createdAt,
                'created_by'                     => $auth->id,
                'status'                         => 'paid',
                'created_at'                     => $createdAt,
                'updated_at'                     => $createdAt,

            ]);

            // 2. CREATE REGULATION (PROPRE)
            $regulation = PaymentRegulation::create([
                'source_uuid' => $expense->uuid,
                'source_type' => 'expense',

                'restaurant_expense_type_uuid' => $request->restaurant_expense_type_uuid,
                'regulation_method_uuid'       => $request->payment_method_uuid,
                'amount'                       => $request->amount,

                'type'                         => 'expense',
                'created_by'                   => $auth->id,
                'updated_by'                   => $auth->id,
                'created_at'                   => $createdAt,
                'updated_at'                   => $createdAt,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dépense enregistrée avec succès.',
                'data'    => [
                    'expense' => $expense,
                    'regulation' => $regulation
                ]
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error("Erreur lors de l'enregistrement de la dépense : " . $e->getMessage(), [
                'exception' => $e,
                'payload'   => $request->all(),
                'user_id'   => $auth?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de l'enregistrement",
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseController::update
     * @permission_desc Modifier une dépense
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'restaurant_expense_type_uuid'   => 'required|uuid',
            'restaurant_expense_family_uuid' => 'nullable|uuid',
            'family_hierarchy_uuids'         => 'nullable|array',
            'family_hierarchy_uuids.*'       => 'uuid',
            'payment_method_uuid'            => 'required|uuid',
            'name'                           => 'required|string|max:255',
            'amount'                         => 'required|numeric|min:0',
            'description'                    => 'nullable|string|max:1000',
            'status'                         => 'nullable|string|max:50',
            'date'                           => 'nullable|date',
        ]);
        $createdAt = $request->filled('date')
            ? Carbon::parse($request->date)->setTimeFrom(Carbon::now())
            : Carbon::now();

        $expense = ExpensePayment::where('uuid', $uuid)->first();

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Dépense introuvable.'
            ], 404);
        }

        DB::beginTransaction();

        try {

            $expense->update([
                'restaurant_expense_type_uuid'   => $request->restaurant_expense_type_uuid,
                'restaurant_expense_family_uuid' => $request->restaurant_expense_family_uuid,
                'hierarchy_uuids'                => $request->family_hierarchy_uuids ?? [],
                'regulation_method_uuid'         => $request->payment_method_uuid,
                'amount'                         => $request->amount,
                'name'                           => $request->name,
                'description'                    => $request->description,
                'status'                         => $request->status ?? $expense->status,
                'updated_by'                     => $auth->id,
                'paid_at'                        => $createdAt,
                'created_at'                   => $createdAt,
                'updated_at'                   => $createdAt,
            ]);

            // 2. FIND OR CREATE REGULATION
            $regulation = PaymentRegulation::where('source_uuid', $expense->uuid)
                ->where('source_type', 'expense')
                ->first();

            if (!$regulation) {
                $regulation = PaymentRegulation::create([
                    'source_uuid'                  => $expense->uuid,
                    'source_type'                  => 'expense',
                    'restaurant_expense_type_uuid' => $request->restaurant_expense_type_uuid,
                    'regulation_method_uuid'       => $request->payment_method_uuid,
                    'amount'                       => $request->amount,
                    'type'                         => 'expense',
                    'paid_at'                        => $createdAt,
                    'created_by'                   => $auth->id,
                    'updated_by'                   => $auth->id,
                    'created_at'                   => $createdAt,
                    'updated_at'                   => $createdAt,
                ]);
            } else {
                $regulation->update([
                    'restaurant_expense_type_uuid' => $request->restaurant_expense_type_uuid,
                    'regulation_method_uuid'       => $request->payment_method_uuid,
                    'amount'                       => $request->amount,
                    'type'                         => 'expense',
                    'updated_by'                   => $auth->id,
                    'created_at'                   => $createdAt,
                    'paid_at'                        => $createdAt,
                    'updated_at'                   => $createdAt,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dépense mise à jour avec succès.',
                'data' => [
                    'expense' => $expense,
                    'regulation' => $regulation
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error("Erreur update dépense : " . $e->getMessage(), [
                'exception' => $e,
                'uuid'      => $uuid,
                'payload'   => $request->all(),
                'user_id'   => $auth?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la mise à jour",
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseController::cancel
     * @permission_desc Annuler une dépense
     */
    public function cancel(Request $request, $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $payment = ExpensePayment::where('uuid', $uuid)->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Dépense introuvable.'
            ], 404);
        }

        if ($payment->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cette dépense est déjà annulée.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            // 1. CANCEL EXPENSE
            $payment->update([
                'status' => 'cancelled',
                'updated_by' => $auth->id,
            ]);

            // 2. DELETE OR SOFT REMOVE REGULATION
            PaymentRegulation::where('source_uuid', $payment->uuid)
                ->where('source_type', 'expense')
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'La dépense a été annulée avec succès.',
                'data' => $payment
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

}

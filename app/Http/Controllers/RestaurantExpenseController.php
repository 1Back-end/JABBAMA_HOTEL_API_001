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

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        } else {
            $query->whereDate('created_at', today());
        }

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
            'category_document'              => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5000',
            'type_document'                  => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5000',
        ]);

        $createdAt = $request->filled('date')
            ? Carbon::parse($request->date)->setTimeFrom(Carbon::now())
            : Carbon::now();

        DB::beginTransaction();

        try {

            $expense = new ExpensePayment([
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
            ]);

            $expense->timestamps = false;
            $expense->created_at = $createdAt;
            $expense->updated_at = $createdAt;
            $expense->save();

            // 1. Enregistrement de la Catégorie avec le préfixe _cat_
            if ($request->hasFile('category_document')) {
                $file = $request->file('category_document');
                $originalName = $file->getClientOriginalName();
                $filename = time() . '_cat_' . $originalName;
                $path = $file->storeAs('expenses', $filename, 'public');

                $expense->medias()->create([
                    'name'      => $originalName,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            // 2. Enregistrement du Type avec le préfixe _type_
            if ($request->hasFile('type_document')) {
                $file = $request->file('type_document');
                $originalName = $file->getClientOriginalName();
                $filename = time() . '_type_' . $originalName;
                $path = $file->storeAs('expenses', $filename, 'public');

                $expense->medias()->create([
                    'name'      => $originalName,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            $regulation = new PaymentRegulation([
                'source_uuid'                  => $expense->uuid,
                'source_type'                  => 'expense',
                'restaurant_expense_type_uuid' => $request->restaurant_expense_type_uuid,
                'regulation_method_uuid'       => $request->payment_method_uuid,
                'amount'                       => $request->amount,
                'type'                         => 'expense',
                'created_by'                   => $auth->id,
                'updated_by'                   => $auth->id,
            ]);

            $regulation->timestamps = false;
            $regulation->created_at = $createdAt;
            $regulation->updated_at = $createdAt;
            $regulation->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dépense enregistrée avec succès.',
                'data'    => [
                    'expense'    => $expense->load('medias'),
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
    public function update_restaurant_expense_details(Request $request, $uuid)
    {
        $auth = auth()->user();

        // 1. Recherche de la dépense
        $expense = ExpensePayment::where('uuid', $uuid)->firstOrFail();

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
            'category_document'              => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5000',
            'type_document'                  => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5000',
        ]);

        $updatedDate = $request->filled('date')
            ? Carbon::parse($request->date)->setTimeFrom(Carbon::now())
            : $expense->created_at;

        DB::beginTransaction();

        try {

            $expense->update([
                'restaurant_expense_type_uuid'   => $request->restaurant_expense_type_uuid,
                'restaurant_expense_family_uuid' => $request->restaurant_expense_family_uuid,
                'regulation_method_uuid'         => $request->payment_method_uuid,
                'hierarchy_uuids'                => $request->family_hierarchy_uuids ?? [],
                'amount'                         => $request->amount,
                'name'                           => $request->name,
                'description'                    => $request->description,
                'paid_at'                        => $updatedDate,
                'updated_by'                     => $auth->id,
            ]);

            $expense->timestamps = false;
            $expense->created_at = $updatedDate;
            $expense->updated_at = Carbon::now();
            $expense->save();

            if ($request->hasFile('category_document')) {
                $oldCategoryMedia = $expense->medias()->where('filename', 'LIKE', '%_cat_%')->first();
                if ($oldCategoryMedia) {
                    if (\Storage::disk($oldCategoryMedia->disk)->exists($oldCategoryMedia->path)) {
                        \Storage::disk($oldCategoryMedia->disk)->delete($oldCategoryMedia->path);
                    }
                    $oldCategoryMedia->delete();
                }

                $file = $request->file('category_document');
                $originalName = $file->getClientOriginalName();
                $filename = time() . '_cat_' . $originalName;
                $path = $file->storeAs('expenses', $filename, 'public');

                $expense->medias()->create([
                    'name'      => $originalName,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            if ($request->hasFile('type_document')) {
                $oldTypeMedia = $expense->medias()->where('filename', 'LIKE', '%_type_%')->first();
                if ($oldTypeMedia) {
                    if (\Storage::disk($oldTypeMedia->disk)->exists($oldTypeMedia->path)) {
                        \Storage::disk($oldTypeMedia->disk)->delete($oldTypeMedia->path);
                    }
                    $oldTypeMedia->delete();
                }

                $file = $request->file('type_document');
                $originalName = $file->getClientOriginalName();
                $filename = time() . '_type_' . $originalName;
                $path = $file->storeAs('expenses', $filename, 'public');

                $expense->medias()->create([
                    'name'      => $originalName,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }
            // Note : Si $request->hasFile('type_document') est faux, l'ancien fichier en base n'est PAS touché !

            $regulation = PaymentRegulation::where('source_uuid', $expense->uuid)
                ->where('source_type', 'expense')
                ->first();

            if ($regulation) {
                $regulation->update([
                    'restaurant_expense_type_uuid' => $request->restaurant_expense_type_uuid,
                    'regulation_method_uuid'       => $request->payment_method_uuid,
                    'amount'                       => $request->amount,
                    'updated_by'                   => $auth->id,
                ]);

                $regulation->timestamps = false;
                $regulation->created_at = $updatedDate;
                $regulation->updated_at = Carbon::now();
                $regulation->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dépense mise à jour avec succès.',
                'data'    => [
                    'expense'    => $expense->load('medias'),
                    'regulation' => $regulation
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error("Erreur lors de la mise à jour de la dépense : " . $e->getMessage(), [
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
     * @permission_desc Annuler le libéllé d'une dépense
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

            $payment->update([
                'status' => 'cancelled',
                'updated_by' => $auth->id,
            ]);

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

    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseController::cancelGroup
     * @permission_desc Annuler la catégorie d'une dépense
     */
    public function cancelGroup(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'password'        => 'required|string',
            'expense_type_id' => 'required|string',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $date = $request->input('date', Carbon::today()->toDateString());

        $payments = ExpensePayment::where('status', '!=', 'cancelled')
            ->whereDate('created_at', $date)
            ->where('restaurant_expense_type_uuid', $request->expense_type_id)
            ->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune dépense active trouvée pour ce groupe.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $paymentUuids = $payments->pluck('uuid')->toArray();

            ExpensePayment::whereIn('uuid', $paymentUuids)->update([
                'status'     => 'cancelled',
                'updated_by' => $auth->id,
            ]);

            PaymentRegulation::whereIn('source_uuid', $paymentUuids)
                ->where('source_type', 'expense')
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Toutes les dépenses du groupe ont été annulées avec succès.',
                'count'   => count($paymentUuids)
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'annulation du groupe.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseController::cancelFamily
     * @permission_desc Annuler la sous-catégorie d'une dépense
     */
    public function cancelFamily(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'password'  => 'required|string',
            'family_id' => 'required|string',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $date = $request->input('date', Carbon::today()->toDateString());
        $familyUuid = $request->family_id;

        $payments = ExpensePayment::where('status', '!=', 'cancelled')
            ->whereDate('created_at', $date)
            ->where(function ($q) use ($familyUuid) {
                $q->where('restaurant_expense_family_uuid', $familyUuid)
                    ->orWhereJsonContains('hierarchy_uuids', $familyUuid);
            })
            ->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune dépense active trouvée pour cette famille.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $paymentUuids = $payments->pluck('uuid')->toArray();

            ExpensePayment::whereIn('uuid', $paymentUuids)->update([
                'status'     => 'cancelled',
                'updated_by' => $auth->id,
            ]);

            PaymentRegulation::whereIn('source_uuid', $paymentUuids)
                ->where('source_type', 'expense')
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Toutes les dépenses de la catégorie ont été annulées avec succès.',
                'count'   => count($paymentUuids)
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'annulation de la catégorie.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }

}

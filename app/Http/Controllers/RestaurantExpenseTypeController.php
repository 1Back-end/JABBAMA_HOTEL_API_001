<?php

namespace App\Http\Controllers;

use App\Models\CashReceiptType;
use App\Models\RestaurantExpenseType;
use Illuminate\Http\Request;
/**
 * @permission_category Gestion des types de dépenses
 * @permission_module Gestion du restaurant
 */
class RestaurantExpenseTypeController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission RestaurantExpenseTypeController::index
     * @permission_desc Afficher la liste des types de dépenses
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
     * @permission_desc Créer un type de dépenses
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'unique:restaurant_expense_types,code'],
            'name' => ['required', 'string', 'unique:restaurant_expense_types,name'],
            'is_linked_to_activity' => ['nullable', 'boolean'],
        ]);

        try {
            $type = RestaurantExpenseType::create([
                'code' => strtoupper($validated['code']),
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
     * @permission_desc Modifier un type de dépenses
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
            'code' => ['required', 'string', 'unique:restaurant_expense_types,code,' . $type->uuid . ',uuid'],
            'name' => ['required', 'string', 'unique:restaurant_expense_types,name,' . $type->uuid . ',uuid'],
            'is_linked_to_activity' => ['nullable', 'boolean'],
        ]);

        $type->update([
            'code' => strtoupper($validated['code']),
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
     * @permission_desc Activer/Désactiver un type de dépenses
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

}

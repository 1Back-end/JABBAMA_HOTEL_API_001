<?php

namespace App\Http\Controllers;

use App\Models\MenuRestaurant;
use App\Models\RestaurantTable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @permission_category Gestion des tables
 * @permission_module Gestion du restaurant
 * @code_module RESTAURANT
 * @permission_module Gestion du bar
 * @code_module BAR
 */
class RestaurantTableController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission RestaurantTableController::store
     * @code BAR, RESTAURANT
     * @permission_desc Créer une table
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            $validated = $request->validate(
                [
                    'table_number' => 'required|string|unique:restaurant_tables,table_number',
                    'capacity'     => 'required|integer|min:1',
                    'description'   => 'string|nullable',
                ],
                [
                    'table_number.required' => 'Le numéro de table est obligatoire.',
                    'table_number.unique'   => 'Ce numéro de table existe déjà.',
                    'capacity.required'     => 'La capacité est obligatoire.',
                    'capacity.integer'      => 'La capacité doit être un nombre.',
                    'capacity.min'          => 'La capacité doit être au moins de 1.',
                ]
            );
            $validated['created_by'] = $auth->id;

           $restaurantTable = RestaurantTable::create($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Table du restaurant créée avec succès.',
                'data'    => $restaurantTable->fresh()
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du menu.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantTableController::update
     * @code BAR, RESTAURANT
     * @permission_desc Modifier une table
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            $restaurantTable = RestaurantTable::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate(
                [
                    'table_number' => 'required|string|unique:restaurant_tables,table_number,' . $restaurantTable->uuid . ',uuid',
                    'capacity'     => 'required|integer|min:1',
                    'description'   => 'string|nullable',
                ],
                [
                    'table_number.required' => 'Le numéro de table est obligatoire.',
                    'table_number.unique'   => 'Ce numéro de table existe déjà.',
                    'capacity.required'     => 'La capacité est obligatoire.',
                    'capacity.integer'      => 'La capacité doit être un nombre.',
                    'capacity.min'          => 'La capacité doit être au moins de 1.',
                ]
            );

            $validated['updated_by'] = $auth->id;

            $restaurantTable->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Table du restaurant mise à jour avec succès.',
                'data'    => $restaurantTable->fresh(),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Table du restaurant introuvable.',
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour de la table.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission RestaurantTableController::show
     * @code BAR, RESTAURANT
     * @permission_desc Afficher les détails d'une table
     */
    public function show(string $uuid)
    {
        try {
            $restaurantTable = RestaurantTable::with(['creator', 'updater'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => $restaurantTable,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Table du restaurant introuvable.',
            ], 404);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des données.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantTableController::update_status
     * @code BAR, RESTAURANT
     * @permission_desc Activer/Désactiver une table
     */
    public function update_status(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate(
            [
                'is_available' => 'required|boolean',
            ],
            [
                'is_available.required' => 'Le statut est obligatoire.',
                'is_available.boolean'  => 'Le statut doit être vrai ou faux.',
            ]
        );

        DB::beginTransaction();

        try {
            $restaurantTable = RestaurantTable::where('uuid', $uuid)->firstOrFail();

            $restaurantTable->update([
                'is_available' => $validated['is_available'],
                'updated_by'   => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $validated['is_available']
                    ? 'La table est maintenant disponible.'
                    : 'La table a été rendue indisponible.',
                'data'    => $restaurantTable->fresh(),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Table du restaurant introuvable.',
            ], 404);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du statut.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission RestaurantTableController::index
     * @code BAR, RESTAURANT
     * @permission_desc Afficher la liste des tables
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = RestaurantTable::with([
            'creator',
            'updater',
        ])->when($request->has('is_available'), function ($query) use ($request) {
            $query->where('is_available', filter_var($request->input('is_available'), FILTER_VALIDATE_BOOLEAN));
        });

        $query->when($request->input('search'), function ($query) use ($request) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('table_number', 'like', "%$search%");
            });
        });
        $query->orderByRaw('CAST(table_number AS UNSIGNED) ASC');

        $data = $query->paginate($perPage, ['*'], 'page', $page);
        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }



    //
}

<?php

namespace App\Http\Controllers;

use App\Models\SalesCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
/**
 * @permission_category Gestion des rubriques de ventes
 * @permission_module Gestion du restaurant
 */
class SalesCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission SalesCategoryController::index
     * @permission_desc Afficher la liste des rubriques de ventes
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = SalesCategory::with([
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
     * @permission SalesCategoryController::store
     * @permission_desc Créer une rubrique de ventes
     */
    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'code' => ['required', 'string', 'max:50', 'unique:sales_categories,code'],
                'name' => ['required', 'string', 'max:150', 'unique:sales_categories,name'],
                'type' => ['required', 'in:time_based,manual'],
                'start_time' => ['nullable', 'date_format:H:i'],
                'end_time' => ['nullable', 'date_format:H:i'],
            ]);

            if ($validated['type'] === 'manual') {
                $validated['start_time'] = null;
                $validated['end_time'] = null;
            }

            if ($validated['type'] === 'time_based') {

                if (empty($validated['start_time']) || empty($validated['end_time'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Les heures de début et de fin sont obligatoires pour une rubrique horaire.'
                    ], 422);
                }

                $start = \Carbon\Carbon::createFromFormat('H:i', $validated['start_time']);
                $end = \Carbon\Carbon::createFromFormat('H:i', $validated['end_time']);

                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                $diffInMinutes = $start->diffInMinutes($end);

                if ($diffInMinutes > 1440) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La plage horaire ne doit pas dépasser 24 heures.'
                    ], 422);
                }
            }

            // ✔️ Création
            $salesCategory = SalesCategory::create([
                'code' => strtoupper($validated['code']),
                'name' => $validated['name'],
                'type' => $validated['type'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Rubrique créée avec succès.',
                'data' => $salesCategory
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            \Log::error('SALES CATEGORY STORE ERROR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la rubrique.'
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission SalesCategoryController::update
     * @permission_desc Modifier une rubrique de ventes
     */
    public function update(Request $request, string $uuid)
    {
        try {
            $salesCategory = SalesCategory::where('uuid', $uuid)->first();

            if (!$salesCategory) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Rubrique introuvable.'
                ], 404);
            }

            $validated = $request->validate([
                'code' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('sales_categories', 'code')
                        ->ignore($salesCategory->uuid, 'uuid')
                ],
                'name' => [
                    'required',
                    'string',
                    'max:150',
                    Rule::unique('sales_categories', 'name')
                        ->ignore($salesCategory->uuid, 'uuid')
                ],
                'type' => ['required', 'in:time_based,manual'],
                'start_time' => ['nullable', 'date_format:H:i'],
                'end_time' => ['nullable', 'date_format:H:i'],
            ]);

            if ($validated['type'] === 'manual') {
                $validated['start_time'] = null;
                $validated['end_time'] = null;
            }

            if ($validated['type'] === 'time_based') {

                if (empty($validated['start_time']) || empty($validated['end_time'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Les heures de début et de fin sont obligatoires pour une rubrique horaire.'
                    ], 422);
                }

                $start = \Carbon\Carbon::createFromFormat('H:i', $validated['start_time']);
                $end = \Carbon\Carbon::createFromFormat('H:i', $validated['end_time']);

                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                $diffInMinutes = $start->diffInMinutes($end);

                if ($diffInMinutes > 1440) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La plage horaire ne doit pas dépasser 24 heures.'
                    ], 422);
                }
            }

            // ✔️ UPDATE
            $salesCategory->update([
                'code' => strtoupper($validated['code']),
                'name' => $validated['name'],
                'type' => $validated['type'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Rubrique modifiée avec succès.',
                'data' => $salesCategory->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            \Log::error('SALES CATEGORY UPDATE ERROR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'uuid' => $uuid
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la modification.'
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission SalesCategoryController::updateStatus
     * @permission_desc Activer/Désactiver une rubrique de ventes
     */
    public function updateStatus(Request $request, string $uuid)
    {
        try {
            $item = SalesCategory::where('uuid', $uuid)->first();

            if (!$item) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Élément introuvable'
                ], 404);
            }

            $request->validate([
                'is_active' => 'required|boolean'
            ]);

            $item->is_active = $request->is_active;
            $item->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Statut mis à jour avec succès',
                'data' => $item
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur serveur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SalesCategoryController::show
     * @permission_desc Afficher les détails d'une rubrique de ventes
     */
    public function show(string $uuid)
    {
        try {
            $item = SalesCategory::where('uuid', $uuid)->first();

            if (!$item) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Élément introuvable'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $item
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur serveur',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

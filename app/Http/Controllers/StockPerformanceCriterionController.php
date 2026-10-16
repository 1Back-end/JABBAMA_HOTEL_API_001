<?php

namespace App\Http\Controllers;

use App\Models\StockPerformanceCriterion;
use Illuminate\Http\Request;
/**
 * @permission_category Gestion des critères de performances sur les stocks
 * @permission_module Gestion des stocks
 * @permission_module Gestion du restaurant
 */

class StockPerformanceCriterionController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission StockPerformanceCriterionController::index
     * @permission_desc Afficher la liste des critères de performances
     */
    public function index(Request $request)
    {
        $criteria = StockPerformanceCriterion::with(['creator:id,nom_utilisateur', 'updater:id,nom_utilisateur'])
            ->orderBy('min_rate', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $criteria
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockPerformanceCriterionController::store
     * @permission_desc Créer un critère de performances
     */
    public function store(Request $request)
    {
        $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'min_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_rate' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_rate'],
            'description_template' => ['required', 'string'],
            'badge_color' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $newMin = (float) $request->input('min_rate');
        $newMax = (float) $request->input('max_rate');

        if ($newMax > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Le taux maximum ne peut pas dépasser 100%.'
            ], 422);
        }

        $overlapping = StockPerformanceCriterion::where(function ($query) use ($newMin, $newMax) {
            $query->whereBetween('min_rate', [$newMin, $newMax])
                ->orWhereBetween('max_rate', [$newMin, $newMax])
                ->orWhere(function ($q) use ($newMin, $newMax) {
                    $q->where('min_rate', '<=', $newMin)
                        ->where('max_rate', '>=', $newMax);
                });
        })->exists();

        if ($overlapping) {
            return response()->json([
                'success' => false,
                'message' => 'Cette plage de taux chevauche un autre critère existant. Veuillez ajuster les valeurs.'
            ], 422);
        }

        $userId = auth()->id();

        $criterion = StockPerformanceCriterion::create([
            'label' => $request->input('label'),
            'min_rate' => $newMin,
            'max_rate' => $newMax,
            'description_template' => $request->input('description_template'),
            'badge_color' => $request->input('badge_color'),
            'is_active' => $request->input('is_active', true),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Critère de performance créé avec succès.',
            'data' => $criterion
        ], 201);
    }

    /**
     * Display a listing of the resource.
     * @permission StockPerformanceCriterionController::show
     * @permission_desc Afficher les détails d'un critère de performances
     */
    public function show($uuid)
    {
        $criterion = StockPerformanceCriterion::with(['creator:id,nom_utilisateur', 'updater:id,nom_utilisateur'])
            ->where('uuid', $uuid)
            ->first();

        if (!$criterion) {
            return response()->json(['success' => false, 'message' => 'Critère introuvable.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $criterion
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockPerformanceCriterionController::update
     * @permission_desc Modifier un critère de performances
     */
    public function update(Request $request, $uuid)
    {
        $criterion = StockPerformanceCriterion::where('uuid', $uuid)->first();

        if (!$criterion) {
            return response()->json(['success' => false, 'message' => 'Critère introuvable.'], 404);
        }

        $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'min_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_rate' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_rate'],
            'description_template' => ['required', 'string'],
            'badge_color' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $newMin = (float) $request->input('min_rate');
        $newMax = (float) $request->input('max_rate');

        if ($newMax > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Le taux maximum ne peut pas dépasser 100%.'
            ], 422);
        }

        $overlapping = StockPerformanceCriterion::where('uuid', '!=', $uuid)
            ->where(function ($query) use ($newMin, $newMax) {
                $query->whereBetween('min_rate', [$newMin, $newMax])
                    ->orWhereBetween('max_rate', [$newMin, $newMax])
                    ->orWhere(function ($q) use ($newMin, $newMax) {
                        $q->where('min_rate', '<=', $newMin)
                            ->where('max_rate', '>=', $newMax);
                    });
            })->exists();

        if ($overlapping) {
            return response()->json([
                'success' => false,
                'message' => 'Cette plage de taux chevauche un autre critère existant. Veuillez ajuster les valeurs.'
            ], 422);
        }

        $criterion->update([
            'label' => $request->input('label'),
            'min_rate' => $newMin,
            'max_rate' => $newMax,
            'description_template' => $request->input('description_template'),
            'badge_color' => $request->input('badge_color'),
            'is_active' => $request->input('is_active', $criterion->is_active),
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Critère mis à jour avec succès.',
            'data' => $criterion
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockPerformanceCriterionController::updateStatus
     * @permission_desc Activer/Désactiver un critère de performances
     */
    public function updateStatus(Request $request, $uuid)
    {
        $criterion = StockPerformanceCriterion::where('uuid', $uuid)->first();

        if (!$criterion) {
            return response()->json(['success' => false, 'message' => 'Critère introuvable.'], 404);
        }

        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $criterion->update([
            'is_active' => $request->input('is_active'),
            'updated_by' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Statut mis à jour avec succès.',
            'data' => $criterion
        ]);
    }

    /**
     * Supprimer un critère.
     */
    public function destroy($uuid)
    {
        $criterion = StockPerformanceCriterion::where('uuid', $uuid)->first();

        if (!$criterion) {
            return response()->json(['success' => false, 'message' => 'Critère introuvable.'], 404);
        }

        $criterion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Critère supprimé avec succès.'
        ]);
    }
}

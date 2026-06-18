<?php

namespace App\Http\Controllers;

use App\Models\CashCollectionFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @permission_category Gestion des familles d'encaissements
 * @permission_module Gestion du restaurant
 */
class CashCollectionFamilyController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission CashCollectionFamilyController::index
     * @permission_desc Afficher la liste des familles d'encaissements
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = CashCollectionFamily::with([
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
     * @permission CashCollectionFamilyController::store
     * @permission_desc Créer une famille d'encaissement
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:cash_collection_families,code',
            'name' => 'required|string|max:255',
            'target_sector' => ['required', Rule::in(['restaurant', 'bar', 'all'])],
            'description' => 'nullable|string|max:500',
        ]);

        $validated['created_by'] = auth()->id();

        $family = CashCollectionFamily::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Famille d\'encaissement créée avec succès.',
            'data' => $family
        ], 201);
    }

    /**
     * Display a listing of the resource.
     * @permission CashCollectionFamilyController::show
     * @permission_desc Afficher les détails d'une famille d'encaissement
     */
    public function show(string $uuid): JsonResponse
    {
        $family = CashCollectionFamily::with(['creator:id,name', 'updater:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $family
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission CashCollectionFamilyController::update
     * @permission_desc Modifier une famille d'encaissement
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $family = CashCollectionFamily::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cash_collection_families', 'code')->ignore($family->uuid, 'uuid')
            ],
            'name' => 'required|string|max:255',
            'target_sector' => ['required', Rule::in(['restaurant', 'bar', 'all'])],
            'description' => 'nullable|string|max:500',
        ]);

        $validated['updated_by'] = auth()->id();

        $family->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Famille d\'encaissement mise à jour avec succès.',
            'data' => $family
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission CashCollectionFamilyController::updateStatus
     * @permission_desc Activer/Désactiver une famille d'encaissement
     */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $family = CashCollectionFamily::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'is_active' => 'required|boolean'
        ]);

        $family->update([
            'is_active' => $validated['is_active'],
            'updated_by' => auth()->id(),
        ]);

        $statusMessage = $family->is_active ? 'activée' : 'désactivée';

        return response()->json([
            'success' => true,
            'message' => "La famille d'encaissement a été {$statusMessage} avec succès.",
            'data' => [
                'uuid' => $family->uuid,
                'is_active' => $family->is_active
            ]
        ]);
    }
}

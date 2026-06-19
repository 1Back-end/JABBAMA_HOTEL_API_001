<?php

namespace App\Http\Controllers;

use App\Models\CashCollectionFamily;
use App\Models\CashReceiptType;
use App\Models\SubCashCollectionFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'cashReceiptType'
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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $cashReceiptType = CashReceiptType::where('is_linked_to_turnover', false)->first();

        if (!$cashReceiptType) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun type d\'encaissement disponible.'
            ], 404);
        }

        $validated['created_by'] = auth()->id();
        $validated['code'] = Str::slug($validated['name'], '_');
        $validated['cash_receipt_type_uuid'] = $cashReceiptType->uuid;

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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $family = CashCollectionFamily::where('uuid', $uuid)->first();

        if (!$family) {
            return response()->json([
                'success' => false,
                'message' => 'Famille d\'encaissement introuvable.'
            ], 404);
        }

        $family->update([
            'name' => strtoupper($validated['name']),
            'code' => Str::slug($validated['name'], '_'),
            'description' => $validated['description'] ?? null,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Famille d\'encaissement modifiée avec succès.',
            'data' => $family->fresh()
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

    /**
     * Display a listing of the resource.
     * @permission CashCollectionFamilyController::insertOrUpdate
     * @permission_desc Créer une sous famille d'encaissement
     */
    public function insertOrUpdate(Request $request)
    {
        $validated = $request->validate([
            'cash_collection_family_uuid' => 'required|uuid',
            'sub_families' => 'required|array',
            'sub_families.*.name' => 'required|string',
            'sub_families.*.description' => 'nullable|string',
        ]);

        $codes = collect($validated['sub_families'])
            ->map(fn($item) => Str::slug(trim($item['name']), '-'));

        if ($codes->count() !== $codes->unique()->count()) {
            return response()->json([
                'status' => false,
                'message' => 'Doublon détecté dans les sous-familles soumises.'
            ], 422);
        }

        try {
            DB::transaction(function () use ($validated) {
                $familyUuid = $validated['cash_collection_family_uuid'];

                SubCashCollectionFamily::where('cash_collection_family_uuid', $familyUuid)->delete();

                foreach ($validated['sub_families'] as $item) {
                    SubCashCollectionFamily::create([
                        'cash_collection_family_uuid' => $familyUuid,
                        'name' => trim($item['name']),
                        'code' => Str::slug(trim($item['name']), '-'),
                        'description' => $item['description'] ?? null,
                        'is_active' => true,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ]);
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Sous-familles réinitialisées et créées avec succès.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors du traitement.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}

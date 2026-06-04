<?php

namespace App\Http\Controllers;

use App\Models\ComplementComposition;
use App\Models\ComplementCompositionItem;
use App\Models\ConfigurationsComplement;
use App\Models\MenuRestaurant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @permission_category Compléments des menus
 * @permission_module Gestion du restaurant
 */
class ConfigurationsComplementController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::index
     * @permission_desc Afficher la liste des compléments des menus
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = ConfigurationsComplement::with([
            'creator',
            'updater',
        ]);

        if ($request->has('is_active')) {
            $isActive = $request->input('is_active') === 'true' ? true : false;
            $query->where('is_active', $isActive);
        }

        if ($request->has('is_confectioned')) {
            $isConfectioned = $request->input('is_confectioned') === 'true' ? true : false;
            $query->where('is_confectioned', $isConfectioned);
        }

        if ($request->filled('menus_restaurant_uuid')) {
            $query->where('menus_restaurant_uuid', $request->menus_restaurant_uuid);
        }
        if ($request->filled('menus_complement_type')) {
            $query->where('menus_complement_type', $request->menus_complement_type);
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('menuRestaurant', function ($qm) use ($search) {
                        $qm->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");

                    });
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
     * @permission ConfigurationsComplementController::store
     * @permission_desc Créer un complément
     */
    public function store(Request $request)
    {
        try {

            $auth = auth()->user();

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],

                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],

                'description' => ['nullable', 'string'],

                'is_active' => ['nullable', 'boolean'],

                'default_price' => ['nullable', 'numeric', 'min:0'],

                'menus_complement_type' => ['nullable', 'string'],
            ]);

            $existingConfig = ConfigurationsComplement::where('name', $validated['name'])
                ->first();

            if ($existingConfig) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce complément existe déjà.'
                ], 400);
            }

            $validated['created_by'] = $auth?->id;

            $validated['prices_for_clients_debtor'] = array_values(
                array_unique(
                    array_map(
                        fn($v) => (float) ($v ?? 0),
                        $validated['prices_for_clients_debtor'] ?? []
                    )
                )
            );

            $validated['prices_for_clients_partner'] = array_values(
                array_unique(
                    array_map(
                        fn($v) => (float) ($v ?? 0),
                        $validated['prices_for_clients_partner'] ?? []
                    )
                )
            );

            $validated['prices_for_clients_free'] = array_values(
                array_unique(
                    array_map(
                        fn($v) => (float) ($v ?? 0),
                        $validated['prices_for_clients_free'] ?? []
                    )
                )
            );

            $config = ConfigurationsComplement::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Complément enregistré avec succès',
                'data' => $config
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::show
     * @permission_desc Afficher les détails d'un complément
     */
    public function show(string $uuid)
    {
        try {

            $config = ConfigurationsComplement::with([
                'creator',
                'updater'
            ])->where('uuid', $uuid)->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complément introuvable'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $config
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::updateStatus
     * @permission_desc Activer/Désactiver un complément
     */
    public function updateStatus(Request $request, string $uuid)
    {
        try {

            $auth = auth()->user();

            $config = ConfigurationsComplement::where('uuid', $uuid)->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complément introuvable'
                ], 404);
            }

            $validated = $request->validate([
                'is_active' => ['required', 'boolean'],
            ]);

            $config->update([
                'is_active' => $validated['is_active'],
                'updated_by' => $auth?->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $validated['is_active']
                    ? 'Complément activé avec succès'
                    : 'Complément désactivé avec succès',
                'data' => $config->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::update
     * @permission_desc Modifier un complément
     */
    public function update(Request $request, string $uuid)
    {
        try {

            $auth = auth()->user();

            $config = ConfigurationsComplement::where('uuid', $uuid)->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complément introuvable'
                ], 404);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],

                'prices_for_clients_debtor' => ['nullable', 'array'],
                'prices_for_clients_partner' => ['nullable', 'array'],
                'prices_for_clients_free' => ['nullable', 'array'],

                'description' => ['nullable', 'string'],

                'is_active' => ['nullable', 'boolean'],

                'default_price' => ['nullable', 'numeric', 'min:0'],


                'menus_complement_type' => ['nullable', 'string'],
            ]);

            $existingConfig = ConfigurationsComplement::where('name', $validated['name'])
                ->where('uuid', '!=', $uuid)
                ->first();

            if ($existingConfig) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce complément existe déjà.'
                ], 400);
            }

            $validated['updated_by'] = $auth?->id;

            $validated['prices_for_clients_debtor'] = array_values(
                array_unique(
                    array_map(
                        fn($v) => (float) ($v ?? 0),
                        $validated['prices_for_clients_debtor'] ?? []
                    )
                )
            );

            $validated['prices_for_clients_partner'] = array_values(
                array_unique(
                    array_map(
                        fn($v) => (float) ($v ?? 0),
                        $validated['prices_for_clients_partner'] ?? []
                    )
                )
            );

            $validated['prices_for_clients_free'] = array_values(
                array_unique(
                    array_map(
                        fn($v) => (float) ($v ?? 0),
                        $validated['prices_for_clients_free'] ?? []
                    )
                )
            );

            $config->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Complément mis à jour avec succès',
                'data' => $config->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getCompositionByComplementUuid(string $commplements_restaurant_uuid)
    {
        $composition = ComplementComposition::with([
            'complement',
            'items.product',
            'creator',
            'updater',
            'warehouse'
        ])
            ->where('commplements_restaurant_uuid', $commplements_restaurant_uuid)
            ->first();

        if (!$composition) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune composition trouvée pour ce complément.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'composition' => $composition,
            'message' => 'Composition du complément récupérée avec succès.'
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission ConfigurationsComplementController::upsert
     * @permission_desc Effectuer la confection des compléments
     */
    public function upsert(Request $request, string $commplements_restaurant_uuid)
    {
        $request->validate([
            'warehouse_uuid' => 'required|uuid',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|uuid',
            'items.*.quantity_used' => 'required|numeric|min:0',
            'items.*.is_optional' => 'nullable|boolean',
        ]);

        $userId = auth()->id();

        DB::beginTransaction();

        try {

            $composition = ComplementComposition::updateOrCreate(
                [
                    'commplements_restaurant_uuid' => $commplements_restaurant_uuid,
                    'warehouse_uuid' => $request->warehouse_uuid,
                ],
                [
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            ConfigurationsComplement::where('uuid', $commplements_restaurant_uuid)
                ->update([
                    'is_confectioned' => true,
                    'updated_by' => $userId,
                ]);

            ComplementCompositionItem::where(
                'complement_uuid',
                $composition->uuid
            )->delete();

            foreach ($request->items as $item) {

                ComplementCompositionItem::create([
                    'complement_uuid' => $composition->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity_used' => $item['quantity_used'],
                    'is_optional' => $item['is_optional'] ?? false,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Composition du complément enregistrée avec succès.',
                'data' => $composition->load([
                    'warehouse',
                    'complement',
                    'items.product',
                    'creator',
                    'updater',
                ]),
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

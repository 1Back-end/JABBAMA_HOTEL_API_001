<?php

namespace App\Http\Controllers;

use App\Models\Floor;
use Illuminate\Http\Request;
/**
 * @permission_category Gestion du service des étages
 * @permission_module Gestion du restaurant
 */
class FloorController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission FloorController::store
     * @permission_desc Créer le service des étages
     */
    public function store(Request $request)
    {
        try {
            $auth = auth()->user();

            // 1. Validation des données
            $validated = $request->validate([
                'name'         => ['required', 'string', 'unique:floors,name'],
                'floor_number' => ['required', 'integer', 'unique:floors,floor_number'],
                'description'  => ['nullable', 'string'],
            ]);

            // 2. Ajout de l'auteur
            $validated['created_by'] = $auth->id;
            $floor = Floor::create($validated);

            return response()->json([
                'success' => true,
                'message' => "L'étage a été créé avec succès dans le système.",
                'floor'   => $floor
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Retourne les erreurs de validation (ex: nom déjà pris)
            return response()->json([
                'success' => false,
                'message' => "Erreur de validation",
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            // Capture toutes les autres erreurs (DB, Système, etc.)
            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la création de l'étage.",
                'error'   => $e->getMessage() // À retirer en production pour la sécurité
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission FloorController::update
     * @permission_desc Modifier le service des étages
     */
    public function update(Request $request,string $uuid)
    {
        $auth = auth()->user();

        // On récupère l'étage par son UUID
        $floor = Floor::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:floors,name,' . $floor->uuid . ',uuid'],
            'floor_number' => ['required', 'integer', 'unique:floors,floor_number,' . $floor->uuid . ',uuid'],
            'description' => ['nullable', 'string'],
        ]);

        $validated['updated_by'] = $auth->id;

        $floor->update($validated);

        return response()->json([
            'success' => true,
            'message' => "Étage mis à jour avec succès",
            'floor' => $floor
        ], 200);
    }



    /**
     * Display a listing of the resource.
     * @permission FloorController::updateStatus
     * @permission_desc Activer/Désactiver le service des étages
     */
    public function updateStatus(Request $request,string $uuid)
    {
        $auth = auth()->user();
        // Récupérer le menu
        $floor = Floor::where('uuid', $uuid)->firstOrFail();

        // Inverser le statut actif
        $floor->is_active = ! $floor->is_active;
        $floor->updated_by = $auth->id;
        $floor->save();

        return response()->json([
            'message'   => 'Statut mis à jour avec succès.',
            'is_active' => $floor->is_active
        ]);

    }


    /**
     * Display a listing of the resource.
     * @permission FloorController::index
     * @permission_desc Afficher la liste de service des étages
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = Floor::with([
            'creator',
            'updater',
        ]);

        if ($request->has('is_active')) {
            $isActive = $request->input('is_active') === 'true' ? true : false;
            $query->where('is_active', $isActive);
        }


        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_access_for_floor_room_services')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('floor_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // 🔹 Pagination
        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);
        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }



}

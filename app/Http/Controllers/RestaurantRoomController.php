<?php

namespace App\Http\Controllers;

use App\Enums\RoomType;
use App\Models\RestaurantRoom;
use App\Models\RestaurantTable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;

/**
 * @permission_category Gestion des chambres
 * @permission_module Gestion du restaurant
 */
class RestaurantRoomController extends Controller
{


    /**
     * Display a listing of the resource.
     * @permission RestaurantRoomController::store
     * @permission_desc Créer une chambre
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'rooms_number' => ['required', 'string', 'max:255', 'unique:restaurant_rooms,rooms_number'],
                'description'  => ['nullable', 'string', 'max:255'],
                'type'         => ['required', new Enum(RoomType::class)],
                'capacity'     => ['required', 'integer', 'min:1'],
                'floor_uuid'   => ['required', 'string', 'max:255','exists:floors,uuid'],
            ]);

            $validated['created_by'] = $auth->id;

            $room = RestaurantRoom::create($validated);

            DB::commit();

            Log::info("Chambre créée avec succès: {$room->rooms_number}");

            return response()->json([
                'status'  => 'success',
                'message' => 'Chambre créée avec succès.',
                'data'    => $room->fresh(),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            Log::warning('Erreur de validation lors de la création d’une chambre', $e->errors());

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Exception lors de la création d’une chambre', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création de la chambre.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantRoomController::update
     * @permission_desc Modifier une chambre
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            $room = RestaurantRoom::findOrFail($uuid);

            $validated = $request->validate([
                'rooms_number' => ['required', 'string', 'max:255', "unique:restaurant_rooms,rooms_number,{$uuid},uuid"],
                'description'  => ['nullable', 'string', 'max:255'],
                'type'         => ['required', new Enum(RoomType::class)],
                'capacity'     => ['required', 'integer', 'min:1'],
                'is_active'    => ['nullable', 'boolean'],
                'floor_uuid'   => ['required', 'string', 'max:255','exists:floors,uuid'],
            ]);

            $validated['updated_by'] = $auth->id;

            // 🔹 Mise à jour
            $room->update($validated);

            DB::commit();

            Log::info("Chambre mise à jour avec succès: {$room->rooms_number}");

            return response()->json([
                'status'  => 'success',
                'message' => 'Chambre mise à jour avec succès.',
                'data'    => $room->fresh(),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            Log::warning('Erreur de validation lors de la mise à jour d’une chambre', $e->errors());

            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Exception lors de la mise à jour d’une chambre', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de la chambre.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantRoomController::update_status
     * @permission_desc Activer/Désactiver une chambre
     */
    public function update_status(Request $request, string $uuid)
    {
        $auth = auth()->user();

        try {
            $room = RestaurantRoom::findOrFail($uuid);

            $validated = $request->validate([
                'is_active' => ['required', 'boolean'],
            ]);
            
            $room->update([
                'is_active' => $validated['is_active'],
                'updated_by' => $auth->id,
            ]);

            \Log::info("Statut de la chambre {$room->rooms_number} mis à jour: " . ($room->is_active ? 'actif' : 'inactif'));

            return response()->json([
                'status' => 'success',
                'message' => 'Statut de la chambre mis à jour avec succès.',
                'data' => $room->fresh(),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la mise à jour du statut de chambre', [
                'message' => $e->getMessage(),
                'uuid' => $uuid
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de mettre à jour le statut de la chambre.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission RestaurantRoomController::show
     * @permission_desc Afficher les détails d'une chambre
     */
    public function show(string $uuid)
    {
        try {
            // 🔹 Récupérer la chambre
            $room = RestaurantRoom::with(['creator', 'updater','floor'])->findOrFail($uuid);

            return response()->json([
                'status' => 'success',
                'data' => $room,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chambre non trouvée.',
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'affichage de la chambre', [
                'message' => $e->getMessage(),
                'uuid' => $uuid
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de récupérer les informations de la chambre.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission RestaurantRoomController::index
     * @permission_desc Afficher la liste des chambres
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = RestaurantRoom::with([
            'creator',
            'updater',
            'floor'
        ]);

        if ($request->has('is_active')) {
            $isActive = $request->input('is_active') === 'true' ? true : false;
            $query->where('is_active', $isActive);
        }


        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_restaurant_rooms')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('rooms_number', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('capacity', 'like', "%{$search}%")
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

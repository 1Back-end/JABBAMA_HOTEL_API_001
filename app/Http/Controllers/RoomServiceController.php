<?php

namespace App\Http\Controllers;

use App\Models\RoomService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
/**
 * @permission_category Tarifaire du Room Service
 * @permission_module Gestion du restaurant
 */
class RoomServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission RoomServiceController::index
     * @permission_desc Afficher le tarifaire du room service
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = RoomService::with(['creator:id,nom_utilisateur', 'editor:id,nom_utilisateur'])
            ->latest();

        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->whereJsonContains('prices', (int) $search)
                    ->orWhere('name', 'like', "%$search%");
            });
        }

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission RoomServiceController::store
     * @permission_desc Créer le tarifaire du room service
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'prices' => 'required|array',
            'prices.*' => 'required|numeric',
        ]);

        $serviceName = !empty($validated['name']) ? $validated['name'] : 'ROOM SERVICE';
        $createdServices = [];

        foreach ($validated['prices'] as $price) {
            $roomService = RoomService::create([
                'name' => $serviceName,
                'prices' => [$price],
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $createdServices[] = $roomService;
        }

        return response()->json([
            'status' => true,
            'message' => 'Room services enregistrés avec succès.',
            'data' => $createdServices,
        ], 201);
    }


    public function show(RoomService $roomService): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $roomService->load(['creator:id,nom_utilisateur', 'editor:id,nom_utilisateur']),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission RoomServiceController::update
     * @permission_desc Modifier le tarifaire du room service
     */
    public function update(Request $request, $uuid): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'prices' => 'required|array',
        ]);

        $roomService = RoomService::where('uuid', $uuid)->firstOrFail();

        $roomService->update([
            'name' => !empty($validated['name']) ? $validated['name'] : 'ROOM SERVICE',
            'prices' => $validated['prices'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Room service mis à jour avec succès.',
            'data' => $roomService,
        ], 200);
    }

    public function destroy(RoomService $roomService): JsonResponse
    {
        $roomService->delete();

        return response()->json([
            'status' => true,
            'message' => 'Room service supprimé avec succès.',
        ]);
    }
}

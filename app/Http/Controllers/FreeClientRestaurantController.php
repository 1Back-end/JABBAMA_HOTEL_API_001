<?php

namespace App\Http\Controllers;

use App\Models\FreeClientRestaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @permission_category Gestion des clients gratuits
 * @permission_module Gestion du restaurant
 */
class FreeClientRestaurantController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission FreeClientRestaurantController::store
     * @permission_desc Créer les clients gratuits du restaurant
     */
    public function store(Request $request)
    {
        $auth = auth()->user();
        DB::beginTransaction();
        try {

            $validated = $request->validate([
                'first_name'          => 'required|string|max:255',
                'last_name'           => 'nullable|string|max:255',
                'phone_number'        => 'required|string|max:255|unique:free_clients_restaurants,phone_number',
                'second_phone_number' => 'nullable|string|max:255|unique:free_clients_restaurants,second_phone_number',
                'address'             => 'nullable|string|max:255',
                'cni_number_file' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf|max:2048',
                'description'         => 'nullable|string',
                'profession'          => 'nullable|string|max:255',
            ]);
            $validated['created_by'] = $auth->id;

            $freeClientRestaurant = FreeClientRestaurant::create($validated);
            if ($request->hasFile('cni_number_file')) {
                $file = $request->file('cni_number_file');
                $path = $file->store('cni_number_file_url', 'public');

                $freeClientRestaurant->medias()->create([
                    'name'      => $file->getClientOriginalName(),
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => basename($path),
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Client gratuit créé avec succès.',
                'data'    => $freeClientRestaurant->fresh()
            ], 201);

        }catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission FreeClientRestaurantController::update_free_clients_restaurants
     * @permission_desc Modifier les clients gratuits du restaurant
     */
    public function update_free_clients_restaurants(Request $request, $uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            $client = FreeClientRestaurant::findOrFail($uuid);

            $validated = $request->validate([
                'first_name'          => 'sometimes|required|string|max:255',
                'last_name'           => 'nullable|string|max:255',
                'phone_number'        => 'sometimes|required|string|max:255|unique:free_clients_restaurants,phone_number,' . $client->uuid . ',uuid',
                'second_phone_number' => 'nullable|string|max:255|unique:free_clients_restaurants,second_phone_number,' . $client->uuid . ',uuid',
                'address'             => 'nullable|string|max:255',
                'cni_number_file'     => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf|max:2048',
                'description'         => 'nullable|string',
                'profession'          => 'nullable|string|max:255',
            ]);

            // Mise à jour des champs
            $client->fill($validated);
            $client->updated_by = $auth->id;

            // Gestion du fichier CNI
            if ($request->hasFile('cni_number_file')) {
                // Supprimer l’ancien fichier si existant
                if ($client->medias()->exists()) {
                    $oldMedia = $client->medias()->first();
                    if (Storage::disk($oldMedia->disk)->exists($oldMedia->path)) {
                        Storage::disk($oldMedia->disk)->delete($oldMedia->path);
                    }
                    $oldMedia->delete();
                }

                $file = $request->file('cni_number_file');
                $path = $file->store('free_clients/cni', 'public');

                $client->medias()->create([
                    'name'      => $file->getClientOriginalName(),
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => basename($path),
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            $client->save();
            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Client gratuit mis à jour avec succès.',
                'data'    => $client->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission FreeClientRestaurantController::show
     * @permission_desc Afficher les détails d'un client gratuit du restaurant
     */
    public function show($uuid)
    {
        try {
            $client = FreeClientRestaurant::with(['creator','updater','medias'])->findOrFail($uuid);
            return response()->json([
                'status'  => 'success',
                'client'    => $client
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Client gratuit non trouvé.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission FreeClientRestaurantController::updateStatus
     * @permission_desc Activer/Désactiver les clients gratuits du restaurant
     */
    public function updateStatus($uuid)
    {
        $auth = auth()->user();
        DB::beginTransaction();

        try {
            $client = FreeClientRestaurant::findOrFail($uuid);

            $client->is_active = !$client->is_active;
            $client->updated_by = $auth->id;
            $client->save();

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => $client->is_active ? 'Client activé' : 'Client désactivé',
                'data'    => $client
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Client gratuit non trouvé.'
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission FreeClientRestaurantController::index
     * @permission_desc Afficher la liste des clients gratuits du restaurant
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = FreeClientRestaurant::with(['creator','updater','medias'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if($search = trim($request->input('search'))){
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('second_phone_number', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('profession', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $config = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $config->items(),
            'current_page' => $config->currentPage(),
            'last_page'    => $config->lastPage(),
            'total'        => $config->total(),
        ]);


    }



}

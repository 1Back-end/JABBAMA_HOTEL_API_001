<?php

namespace App\Http\Controllers;

use App\Models\MenuRestaurant;
use App\Models\RestaurantPartner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @permission_category Gestion des partenaires
 */
class PartenaireController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission PartenaireController::store
     * @permission_desc Créer les partenaires du restaurant
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'first_name'          => 'required|string|max:255',
                'last_name'           => 'nullable|string|max:255',
                'email'               => 'nullable|string|email|max:255|unique:restaurant_partners,email',
                'phone_number'        => 'required|string|max:255|unique:restaurant_partners,phone_number',
                'second_phone_number' => 'nullable|string|max:255|unique:restaurant_partners,second_phone_number',
                'address'             => 'nullable|string|max:255',
                'logo'                => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'description'         => 'nullable|string',
                'cni_number'          => 'nullable|string|max:255|unique:restaurant_partners,cni_number',
                'is_whatsapp'         => 'nullable|boolean',
                'is_second_whatsapp' => 'nullable|boolean',
            ]);

            // 🔹 Ajout des champs automatiques
            $validated['created_by'] = $auth->id;

            $partner = RestaurantPartner::create($validated);

            // 🔹 Gestion du logo
            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $path = $file->store('logo_partners', 'public');

                $partner->medias()->create([
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
                'message' => 'Partenaire créé avec succès.',
                'data'    => $partner->fresh()
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // ✅ Gestion propre des erreurs de validation
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            // ✅ Gestion des autres exceptions
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création du fournisseur.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PartenaireController::update
     * @permission_desc Modifier les partenaires du restaurant
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        DB::beginTransaction();

        try {
            // 🔹 Récupérer le partenaire
            $partner = RestaurantPartner::where('uuid', $uuid)->firstOrFail();

            // 🔹 Validation
            $validated = $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name'  => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255', Rule::unique('restaurant_partners', 'email')->ignore($partner->uuid, 'uuid'),],
                'phone_number' => ['required', 'string', 'max:255', Rule::unique('restaurant_partners', 'phone_number')->ignore($partner->uuid, 'uuid'),],
                'second_phone_number' => ['nullable', 'string', 'max:255', Rule::unique('restaurant_partners', 'second_phone_number')->ignore($partner->uuid, 'uuid'),],
                'cni_number' => ['nullable', 'string', Rule::unique('restaurant_partners', 'cni_number')->ignore($partner->uuid, 'uuid'),],
                'address'     => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'active'      => ['nullable', 'boolean'],
                'logo'        => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
                'is_whatsapp' => ['nullable', 'boolean'],
                'is_second_whatsapp' => ['nullable', 'boolean'],
            ]);


            $validated['updated_by'] = $auth->id;

            // 🔹 Mise à jour des données
            $partner->update($validated);

            // 🔹 Gestion du nouveau logo
            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $path = $file->store('logo_partners', 'public');

                $partner->medias()->create([
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
                'message' => 'Partenaire mis à jour avec succès.',
                'data'    => $partner->fresh()
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // ✅ Gestion propre des erreurs de validation
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            // ✅ Gestion des autres exceptions
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création du fournisseur.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PartenaireController::show
     * @permission_desc Afficher les détails d'un partenaire du restaurant
     */
    public function show(string $uuid)
    {
        try {
            $partner = RestaurantPartner::with([
                'creator',
                'updater',
                'medias'
            ])->where('uuid', $uuid)->firstOrFail();

            return response()->json([
                'status'  => 'success',
                'message' => 'Partenaire récupéré avec succès.',
                'partner'    => $partner
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Partenaire introuvable.',
                'details' => $e->getMessage()
            ], 404);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PartenaireController::updateStatus
     * @permission_desc Activer/Désactiver les partenaires du restaurant
     */
    public function updateStatus(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'active' => 'required|boolean',
        ]);

        try {
            $partner = RestaurantPartner::where('uuid', $uuid)->firstOrFail();

            $partner->active = $request->active;
            $partner->updated_by = $auth->id;
            $partner->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'Statut du partenaire mis à jour avec succès.',
                'data'    => [
                    'uuid'   => $partner->uuid,
                    'active' => $partner->active,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la mise à jour du statut.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PartenaireController::index
     * @permission_desc Afficher la liste des partenaires du restaurant
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = RestaurantPartner::with([
            'creator',
            'updater',
            'medias',
        ]);

        if ($request->has('active')) {
            $isActive = $request->input('active') === 'true' ? true : false;
            $query->where('active', $isActive);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start, $end]);
        }

        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_restaurant_partners')) {
            $query->where(function ($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                }
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('second_phone_number', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
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

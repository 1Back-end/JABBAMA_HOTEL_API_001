<?php

namespace App\Http\Controllers;

use App\Models\ModuleApplications;
use App\Models\ModulePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
/**
 * @permission_category Gestion des modules d'applications
 * @permission_module Gestion des stocks
 * @code_module STOCK
 * @permission_module Gestion du restaurant
 * @code_module RESTAURANT
 * @permission_module Gestion du bar
 * @code_module BAR
 */
class ModuleApplicationsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @permission ModuleApplicationsController::index
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher la liste des modules d'applications
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');

        $perPage = $request->input('limit', 5);
        $page = $request->input('page', 1);

        $query = ModuleApplications::select([
            'uuid',
            'name',
            'slug',
            'icon',
            'description',
            'is_active',
            'created_by',
            'updated_by',
            'is_first_module',
            'code'
        ])
            ->with([
                'permissions:id,name',
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
            ])
            ->where('name', '!=', 'Autres Modules')
            ->whereNotNull('name')
            ->where('name', '!=', '');

        if ($request->has('is_active')) {
            $query->where(
                'is_active',
                $request->input('is_active') === 'true'
            );
        }
        if (
            !$auth->is_super_admin
        ) {

            if ($auth->can('view_role_related_data')) {
                $query->whereHas('creator.roles', function ($qr) use ($roleIds) {
                    $qr->whereIn('roles.id', $roleIds);
                });
            }
        }
        if ($search = trim($request->input('search'))) {

            $query->where(function ($q) use ($search) {

                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('icon', 'like', "%{$search}%");
            });
        }

        $data = $query
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        try {
            // 🔹 Validation
            $validated = $request->validate([
                'name' => 'required|string|unique:modules_applications,name',
                'icon' => 'nullable|string',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'permissions' => 'nullable|array',
                'permissions.*' => 'integer|exists:permissions,id',
                'is_first_module' => 'boolean',
            ]);

            // 🔹 Vérifier s'il y a déjà un module par défaut
            if ($validated['is_first_module']) {
                $existsPrimary = ModuleApplications::where('is_first_module', true)->exists();

                if ($existsPrimary) {
                    return response()->json([
                        'success' => false,
                        'message' => "Il existe déjà un module principal. Vous ne pouvez en créer qu’un seul.",
                    ], 422);
                }
            }

            $slug = Str::slug($validated['name']);

            // 🔹 Sécurité : unicité du slug
            $originalSlug = $slug;
            $i = 1;
            while (ModuleApplications::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $i++;
            }

            // 🔹 Créer le module
            $module = ModuleApplications::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'icon' => $validated['icon'] ?? null,
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $auth->id,
                'is_first_module' => $validated['is_first_module'] ?? false,
            ]);

            // 🔹 Ajouter les permissions
            if (!empty($validated['permissions'])) {
                foreach ($validated['permissions'] as $permissionId) {
                    ModulePermission::create([
                        'module_uuid' => $module->uuid,
                        'permission_id' => $permissionId,
                        'created_by' => $auth->id,
                    ]);
                }
            }

            $module->load('permissions');

            return response()->json([
                'message' => 'Module créé avec succès',
                'module' => $module
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la création du module',
                'error' => $e->getMessage()
            ], 500);
        }
    }




    /**
     * Display the specified resource.
     */
    public function show($uuid)
    {
        // 🔹 Récupérer le module ou renvoyer 404
        $module = ModuleApplications::with('permissions')->findOrFail($uuid);

        return response()->json([
            'module' => $module
        ], 200);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            $module = ModuleApplications::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'name' => "required|string|unique:modules_applications,name,{$uuid},uuid",
                'icon' => 'nullable|string',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'permissions' => 'nullable|array',
                'permissions.*' => 'integer|exists:permissions,id',
                'is_first_module' => 'boolean',
            ]);

            $slug = $module->slug;

            // 🔹 Sécurité : unicité du slug
            if ($validated['name'] !== $module->name) {
                $slug = Str::slug($validated['name']);
                $originalSlug = $slug;
                $i = 1;
                while (
                ModuleApplications::where('slug', $slug)
                    ->where('uuid', '!=', $module->uuid)
                    ->exists()
                ) {
                    $slug = $originalSlug . '-' . $i++;
                }
            }

            if ($validated['is_first_module']) {
                $alreadyPrimary = ModuleApplications::where('uuid', '!=', $module->uuid)
                    ->where('is_first_module', true)
                    ->exists();

                if ($alreadyPrimary) {
                    return response()->json([
                        'success' => false,
                        'message' => "Il existe déjà un module principal. Vous ne pouvez en avoir qu’un seul.",
                    ], 422);
                }
            }

            // 🔹 Mise à jour du module
            $module->update([
                'name' => $validated['name'],
                'slug' => $slug,
                'icon' => $validated['icon'] ?? null,
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? $module->is_active,
                'is_first_module' => $validated['is_first_module'] ?? $module->is_first_module,
                'updated_by' => $auth->id,
            ]);

            // 🔹 Synchroniser les permissions
            if (isset($validated['permissions'])) {
                $module->permissions()->sync($validated['permissions']);
            }

            $module->load('permissions');

            return response()->json([
                'message' => 'Module mis à jour avec succès',
                'module' => $module
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la mise à jour du module',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function toggleActive($uuid)
    {
        $auth = auth()->user();

        // 🔹 Récupérer le module
        $module = ModuleApplications::findOrFail($uuid);

        // 🔹 Bascule le statut
        $module->is_active = !$module->is_active;
        $module->updated_by = $auth->id;
        $module->save();

        return response()->json([
            'message' => $module->is_active ? 'Module activé avec succès' : 'Module désactivé avec succès',
            'module' => $module
        ], 200);
    }

    public function get_permissions_by_module($uuid)
    {
        $module = ModuleApplications::with('permissions')->where('uuid', $uuid)->first();

        if (!$module) {
            return response()->json([
                'message' => 'Module introuvable',
            ], 404);
        }

        return response()->json([
            'module_uuid' => $module->uuid,
            'module_name' => $module->name,
            'permissions' => $module->permissions,
        ]);
    }

    public function get_combined_permissions(Request $request)
    {
        $uuids = $request->query('uuids');

        $query = ModuleApplications::with('permissions');

        if (!empty($uuids)) {
            if (is_string($uuids)) {
                $uuids = array_map('trim', explode(',', $uuids));
            }
            $query->whereIn('uuid', $uuids);
        }

        $modules = $query->get();

        $permissions = $modules->flatMap(function ($module) {
            return $module->permissions;
        })->unique('id')->values();

        return response()->json([
            'status' => 'success',
            'modules' => $modules,
            'permissions' => $permissions,
        ]);
    }




    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

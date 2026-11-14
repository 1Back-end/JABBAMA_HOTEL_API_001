<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Notifications\DefaultUserCreated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
/**
 * @permission_category Gestion des utilisateurs
 * @permission_module Gestion des stocks
 * @code_module STOCK
 * @permission_module Gestion du restaurant
 * @code_module RESTAURANT
 * @permission_module Gestion du bar
 * @code_module BAR
 */
class UserController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission UserController::index
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher la liste des utilisateurs
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('roles.active', 1);
            })
            ->when($request->input('roles'), function ($query) use ($request) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('roles.active', 1)
                        ->whereIn('id', $request->input('roles'));
                });
            })
            ->when($request->input('permissions'), function ($query) use ($request) {
                $query->whereHas('permissions', function ($q) use ($request) {
                    $q->whereIn('id', $request->input('permissions'));
                });
            })
            ->when($request->input('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('login', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%");
                });
            })
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->boolean('status'));
            })
            ->whereNot('login', ['SYSTEM', 'admin'])
            ->with([
                'roles:id,name',
                'permissions:id,name',
                'createdBy',
                'updatedBy',
            ])
            ->latest()
            ->paginate(
                perPage: $request->input('per_page', 25),
                page: $request->input('page', 1)
            );

        return response()->json([
            'users' => $users,
        ]);
    }


    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission UserController::get_users_where_role_is_gestionnaire_stock
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher la liste des gestionnaires stocks
     */
    public function get_users_where_role_is_gestionnaire_stock(Request $request): JsonResponse
    {
        $users = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'GESTIONNAIRE_STOCK');
            })
            ->when($request->input('permissions'), function ($query) use ($request) {
                $query->whereHas('permissions', function ($q) use ($request) {
                    $q->whereIn('id', $request->input('permissions'));
                });
            })
            ->when($request->input('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('login', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%");
                });
            })
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->boolean('status'));
            })
            ->whereNot('login', ['SYSTEM', 'admin'])
            ->with([
                'roles:id,name',
                'permissions:id,name',
                'createdBy',
                'updatedBy',
            ])
            ->latest()
            ->paginate(
                perPage: $request->input('per_page', 25),
                page: $request->input('page', 1)
            );

        return response()->json([
            'users' => $users,
        ]);
    }



    public function get_users_where_role_is_cuisinier(Request $request): JsonResponse
    {
        $users = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'CUISINIER');
            })
            ->when($request->input('permissions'), function ($query) use ($request) {
                $query->whereHas('permissions', function ($q) use ($request) {
                    $q->whereIn('id', $request->input('permissions'));
                });
            })
            ->when($request->input('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('login', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%");
                });
            })
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->boolean('status'));
            })
            ->whereNot('login', ['SYSTEM', 'admin'])
            ->with([
                'roles:id,name',
                'permissions:id,name',
                'createdBy',
                'updatedBy',
            ])
            ->latest()
            ->paginate(
                perPage: $request->input('per_page', 5),
                page: $request->input('page', 1)
            );

        return response()->json([
            'users' => $users,
        ]);
    }



    /**
     * @param UserRequest $request
     * @return JsonResponse
     *
     * @permission UserController::store
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Créer un utilisateur
     * @throws \Throwable
     */
    public function store(UserRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {

            $defaultPassword = '1234567';
            $user = User::create(array_merge(
                $request->validated(),
                [
                    'password' => Hash::make($defaultPassword),
                    'default'  => true
                ]
            ));

            foreach ($request->roles as $role) {
                $user->roles()->attach($role, [
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => __("L'utilisateur a été créé avec succès !"),
                'login'   => $user->login,
                'password'=> $defaultPassword
            ], Response::HTTP_CREATED);

        } catch (\Exception $th) {
            DB::rollBack();
            Log::error($th->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur est survenue lors de la création de l'utilisateur.",
                'error'   => $th->getMessage(),
            ], 500);
        }
    }



    /**
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::show
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Afficher les détails d'un utilisateur
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => $user->load(['roles:id,name']),
        ]);
    }

    /**
     * @param UserRequest $request
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::update
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Modifier un utilisateur
     */
    public function update(UserRequest $request, User $user): JsonResponse
    {
        DB::beginTransaction();
        try {
            $data = $request->except('password');
            if ($request->password) {
                $data['password'] = Hash::make($request->password);
            }

            $user->update($data);

            $user->roles()->detach();
            foreach ($request->roles as $role) {
                $user->roles()->attach($role, [
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ]);
            }

        } catch (\Exception $th) {
            DB::rollBack();
            Log::error($th->getMessage());

            return response()->json([
                'error' => $th->getMessage(),
            ], 500);
        }
        DB::commit();

        return response()->json([
            'message' => __("Utilisateur a été mis à jour avec succès !"),
        ]);
    }

    /**
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::changeStatus
     * @code STOCK, BAR, RESTAURANT
     * @permission_desc Activer/Désactiver utilisateur
     */
    public function changeStatus(Request $request, User $user): JsonResponse
    {
        $request->validate(['status' => ['required', 'boolean']]);

        $user->update(['status' => $request->status]);

        return response()->json([
            'message' => __('L\'utilisateur a été mis à jour avec succès !'),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // TODO: A faire plus tard
    }
}

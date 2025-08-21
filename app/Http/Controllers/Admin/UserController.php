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
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission UserController::index
     * @permission_desc Afficher la liste des utilisateurs
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::when($request->input('roles'), function (Builder $query) use ($request) {
            $query->whereHas('roles', function (Builder $query) use ($request) {
                $query->whereIn('id', $request->input('roles'));
            });
        })
            ->when($request->input('permissions'), function (Builder $query) use ($request) {
                $query->whereHas('permissions', function (Builder $query) use ($request) {
                    $query->whereIn('id', $request->input('permissions'));
                });
            })
            ->with([
                'client:id,nomcomplet_client',
                'roles:id,name',
                'createdBy:id,nom_utilisateur',
                'updatedBy:id,nom_utilisateur',
                'permissions:id,name',
            ])
            ->whereNot('login', 'SYSTEM')
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
     * @param UserRequest $request
     * @return JsonResponse
     *
     * @permission UserController::store
     * @permission_desc Créer un utilisateur
     * @throws \Throwable
     */
    public function store(UserRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = User::create(array_merge($request->validated(), ['password' => Hash::make($request->password), 'default' => true]));

            $user->notify(new DefaultUserCreated($user->login, $request->password));


            // Associée aux roles
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
            'message' => __("L'utilisateur a été crée avec success !")
        ], Response::HTTP_CREATED);
    }

    /**
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::show
     * @permission_desc Afficher un utilisateur
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

            // Associée au centre
            $user->centres()->detach();


            $user->roles()->detach();
            // Associée aux roles
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
     * @permission_desc Changer le status de l'utilisateur
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

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Centre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();
        $user = $request->user();
        if (!$user->status) {
            return response()->json([
                "status" => "error",
                "message" => "Votre compte est désactivé !"
            ], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }


        $permissions = load_permissions($user);
        $roles = $user->roles()->pluck('name');

        $access = $request->user()->createToken(
            name: config('app.name'),
            abilities: $permissions,
            expiresAt: now()->addMinutes(config('sanctum.expiration'))
        );

        $user->increment('connexion_counter');

        return \response()->json([
            'access_token' => $access->plainTextToken,
            'expire_in' => $access->accessToken->expires_at,
            'new_user' => $user->default,
            'permissions' => $permissions,
            'user' => $user,
            'roles' => $roles,
        ]);
    }


    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }


    public function refresh(Request $request)
    {
        $user = $request->user();

        if (!$user->status) {
            return response()->json([
                "status" => "error",
                "message" => "Votre compte est désactivé !"
            ], 401);
        }

        $permissions = load_permissions($user);

        // Recharge les rôles
        $roles = $user->roles()->pluck('name');

        return response()->json([
            'status' => 'success',
            'permissions' => $permissions,
            'roles' => $roles,
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Non authentifié'
            ], 401);
        }

        if (!$user->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Votre compte est désactivé'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'user' => $user,
            'roles' => $user->roles()->pluck('name'),
            'permissions' => load_permissions($user),
        ]);
    }

}

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $allRoles = $user->roles()->get();
        $activeRoles = $allRoles->filter(fn($r) => (bool)$r->active === true)->pluck('name')->toArray();


        $permissions = load_permissions($user);
        $roles = $activeRoles;

        $access = $request->user()->createToken(
            name: config('app.name'),
            abilities: $permissions,
            expiresAt: now()->addMinutes(config('sanctum.expiration'))
        );

        $user->increment('connexion_counter');
        $superAdmin = \App\Models\User::where('is_super_admin', true)->first();
        $licenseData = null;

        if ($superAdmin) {
            $license = \App\Models\License::where('user_id', $superAdmin->id)
                ->where('status', 'ACTIVE')
                ->where('expires_at', '>', \Carbon\Carbon::now())
                ->first();

            if ($license) {
                $licenseData = [
                    'format' => $license->format,
                    'issuer' => $license->issuer,
                    'client' => [
                        'id' => $license->client_external_id,
                        'name' => $license->client_name,
                        'email' => $license->client_email,
                    ],
                    'application' => [
                        'id' => $license->app_id,
                        'code' => $license->app_code,
                        'name' => $license->app_name,
                    ],
                    'license' => [
                        'reference' => $license->reference,
                        'license_key' => $license->license_key,
                        'status' => $license->status,
                        'jti' => $license->jti,
                    ],
                    'modules' => $license->active_modules,
                    'modules_details' => $license->modules_status_details,
                    'module_entitlements' => $license->module_entitlements,
                    'issued_at' => $license->issued_at,
                    'expires_at' => $license->expires_at,
                    'generated_at' => $license->generated_at,
                ];
            }
        }

        return \response()->json([
            'access_token' => $access->plainTextToken,
            'expire_in' => $access->accessToken->expires_at,
            'new_user' => $user->default,
            'permissions' => $permissions,
            'user' => $user,
            'roles' => $roles,
            'license' => $licenseData,
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

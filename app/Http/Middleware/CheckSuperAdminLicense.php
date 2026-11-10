<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\License;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperAdminLicense
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !isset($user->is_super_admin) || !$user->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Réservé aux super administrateurs.'
            ], 403);
        }

        $license = License::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$license) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_ACTIVE_LICENSE',
                'message' => 'Aucune licence active trouvée pour ce super administrateur. Veuillez enregistrer un certificat valide.'
            ], 403);
        }

        return $next($request);
    }
}

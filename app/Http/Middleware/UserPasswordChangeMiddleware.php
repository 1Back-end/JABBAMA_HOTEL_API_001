<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserPasswordChangeMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->user()->default) {
            return response()->json([
                'message' => __("Le mot de passe et le login par défaut doivent être changé !")
            ], Response::HTTP_CONFLICT);
        }

        return $next($request);
    }
}

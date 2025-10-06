<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SaveLastApiUrl
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            // Ignorer login/logout
            if (!$request->is('api/login') && !$request->is('api/logout')) {
                Cache::put('user_last_url_' . $request->user()->id, $request->fullUrl(), now()->addHours(2));
            }
        }

        return $next($request);
    }
}

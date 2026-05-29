<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseTimeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $executionTime = round((microtime(true) - LARAVEL_START) * 1000, 2);

        if (method_exists($response, 'headers') && $response->headers !== null) {
            $response->headers->set('X-Response-Time', $executionTime . 'ms');
        }

        Log::info('Route exécutée', [
            'method' => $request->method(),
            'route' => $request->getRequestUri(),
            'execution_time' => $executionTime . 'ms',
            'user_id' => auth()->id(),
        ]);


        if ($executionTime > 500) {
            Log::warning(
                "Route lente détectée : {$request->method()} {$request->getRequestUri()} - Temps : {$executionTime}ms"
            );
        }

        return $response;
    }
}

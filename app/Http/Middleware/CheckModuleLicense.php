<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\LicenseService; // <-- N'oubliez pas d'importer le service

class CheckModuleLicense
{
    protected LicenseService $licenseService;

    /**
     * Crée une nouvelle instance du middleware.
     */
    public function __construct(LicenseService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $isClientActive = $this->licenseService->isModuleActive($moduleCode);

        if (!$isClientActive) {
            return response()->json([
                "error" => "MODULE_INACTIVE",
                "message" => "Le module {$moduleCode} n'est pas inclus dans votre licence active.",
                "module_code" => $moduleCode
            ], 403);
        }

        return $next($request);
    }
}

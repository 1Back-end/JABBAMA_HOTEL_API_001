<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    public function handle(Request $request, Closure $next)
    {
        $controller = $request->route()->getControllerClass();
        $method = $request->route()->getActionMethod();

        $requiredPermissions = $this->getMethodPermissions($controller, $method);

        if (!empty($requiredPermissions) && !$this->userHasPermission($requiredPermissions)) {
//            return response()->json(['message' => 'Vous avez accès à une ressource dont vous n\'etes pas autorisé'], 403);
        }

        return $next($request);
    }

    private function getMethodPermissions($controller, $method): array
    {
        if (!class_exists($controller)) {
            return [];
        }

        $reflection = new \ReflectionClass($controller);
        if (!$reflection->hasMethod($method)) {
            return [];
        }

        $method = $reflection->getMethod($method);
        $docComment = $method->getDocComment();

        return $docComment ? $this->extractTagValues($docComment) : [];
    }

    private function extractTagValues($doc): array
    {
        preg_match_all('/' . preg_quote('@permission') . '\s+(.+)/', $doc, $matches);
        return array_map('trim', $matches[1] ?? []);
    }

    private function userHasPermission(array $requiredPermissions): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        // Vérifie les permissions sans centre
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, load_permissions($user))) {
                return false;
            }
        }

        return true;
    }
}

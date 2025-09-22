<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAcademicYear
{
    public function handle(Request $request, Closure $next)
    {
        // Vérifie l'année académique si le paramètre existe
        $year = $request->route('academic_year');
        if ($year && $year->isClosed()) {
            return response()->json([
                'message' => 'Cette année académique est fermée. Aucune action n’est autorisée.'
            ], 403);
        }

        // Vérifie le semestre si le paramètre existe
        $semester = $request->route('semester');
        if ($semester && $semester->isClosed()) {
            return response()->json([
                'message' => 'Ce semestre est terminé. Aucune action n’est autorisée.'
            ], 403);
        }

        return $next($request);
    }
}

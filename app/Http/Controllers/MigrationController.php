<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;


class MigrationController extends Controller
{
    public function run_migrations(Request $request)
    {
        $auth = auth()->user();

        // Validation du mot de passe envoyé
        $request->validate([
            'password' => 'required|string'
        ]);

        // Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        // Exécution des migrations
        try {
            Artisan::call('migrate', [
                '--force' => true
            ]);

            return response()->json([
                'status' => 'success',
                'message' => '✅ Migration effectuée avec succès !'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la migration : ' . $e->getMessage()
            ], 500);
        }
    }
    //
}

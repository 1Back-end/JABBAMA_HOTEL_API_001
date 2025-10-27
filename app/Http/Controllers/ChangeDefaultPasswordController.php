<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ChangeDefaultPasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'new_password' => ['required', 'min:6'], // règles pour le nouveau mot de passe
        ]);

        $user = auth()->user(); // l'utilisateur est déjà connecté avec son login et mot de passe par défaut

        // Vérifier que le nouveau mot de passe est différent de l'ancien
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'message' => 'Le nouveau mot de passe doit être différent de l’ancien.'
            ], Response::HTTP_CONFLICT);
        }

        // Mettre à jour le mot de passe et indiquer que ce n'est plus le mot de passe par défaut
        $user->update([
            'password' => Hash::make($request->new_password),
            'default' => false,
        ]);

        return response()->json([
            'message' => 'Votre mot de passe a été changé avec succès ! Vous pouvez maintenant vous connecter normalement.'
        ]);
    }
}

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
            'login'        => ['required', 'string'],
            'new_password' => ['required', 'min:6'],
        ]);

        // Utilisateur connecté via Bearer token
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message' => 'Utilisateur non authentifié.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 🔐 Vérifier que le login envoyé correspond à l'utilisateur connecté
        if ($request->login !== $user->login) {
            return response()->json([
                'message' => 'Le login fourni ne correspond pas à votre compte.'
            ], Response::HTTP_FORBIDDEN);
        }

        // ❌ Nouveau mot de passe identique à l'ancien
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'message' => 'Le nouveau mot de passe doit être différent de l’ancien.'
            ], Response::HTTP_CONFLICT);
        }

        // ✅ Mise à jour du mot de passe
        $user->update([
            'password' => Hash::make($request->new_password),
            'default'  => false,
        ]);

        return response()->json([
            'message' => 'Votre mot de passe a été changé avec succès ! Vous pouvez maintenant vous connecter normalement.'
        ], Response::HTTP_OK);
    }


}

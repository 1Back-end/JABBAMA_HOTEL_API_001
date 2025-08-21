<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ChangeDefaultPasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'login' => ['required'],
            'new_password' => ['required']
        ]);

        $user = auth()->user();

        if (User::whereNot('id', $user->id)->whereLogin($request->login)->exists()) {
            return \response()->json([
                'message' => __("Ce login est déjà existant"),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (Hash::check($request->new_password, $user->password) || $user->login === $request->login) {
            return response()->json([
                'message' => 'Le nouveau mot de passe et le login doivent être différent de ceux existants.'
            ], Response::HTTP_CONFLICT);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'default' => false,
            'login' => $request->login
        ]);

        return response()->json(['message' => 'Le mot de passe a été changé avec succès !']);
    }
}

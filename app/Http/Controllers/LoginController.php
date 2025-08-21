<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $request->validate([
            "login" => ["required", 'exists:users,login'],
            "password" => ["required",]
        ]);

        // L'utilisateur doit avoir le role administration
        $user = User::whereLogin($request->login)->first();

        if (!$user->hasRole('administration')) {
            return back()->with('error', 'Vous devez être connecté en tant qu\'administrateur.');
        }

        auth()->login($user);

        return redirect()->route('activity');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class RestaurantMaintenanceController extends Controller
{
    public function cleanAbandoned()
    {
        Artisan::call('restaurant:clean-abandoned');
        return response()->json([
            'status' => true,
            'message' => 'Nettoyage des réservations abandonnées exécuté avec succès'
        ]);
    }
}

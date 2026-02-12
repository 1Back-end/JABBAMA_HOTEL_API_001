<?php

namespace App\Http\Controllers;

use App\Models\Country;

class CountryController extends Controller
{
    public function index()
    {
        return response()->json([
            'countries' => Country::select(['name', 'iso2', 'phonecode', 'flag'])->get()
        ]);
    }
}

<?php

use Illuminate\Support\Facades\Route;

Route::get('/countries', [\App\Http\Controllers\CountryController::class, 'index']);

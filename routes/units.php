<?php

use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;
Route::apiResource("units", UnitController::class);
Route::patch('units/{uuid}/is_active', [UnitController::class, 'update_status']);

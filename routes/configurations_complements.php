<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('configurations_complements', \App\Http\Controllers\ConfigurationsComplementController::class);
Route::patch('configurations_complements/{uuid}/is_active', [\App\Http\Controllers\ConfigurationsComplementController::class, 'updateStatus']);

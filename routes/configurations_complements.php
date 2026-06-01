<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('configurations_complements', \App\Http\Controllers\ConfigurationsComplementController::class);
Route::patch('configurations_complements/{uuid}/is_active', [\App\Http\Controllers\ConfigurationsComplementController::class, 'updateStatus']);
Route::get('/configurations_complements/{commplements_restaurant_uuid}/show', [\App\Http\Controllers\ConfigurationsComplementController::class, 'getCompositionByComplementUuid']);
Route::post('/configurations_complements/{commplements_restaurant_uuid}/store', [\App\Http\Controllers\ConfigurationsComplementController::class, 'upsert']);

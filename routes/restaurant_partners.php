<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('restaurant_partners', \App\Http\Controllers\PartenaireController::class);
Route::patch('restaurant_partners/{uuid}/is_active', [\App\Http\Controllers\PartenaireController::class, 'updateStatus']);
Route::post('restaurant_partners/{uuid}/update_partners', [\App\Http\Controllers\PartenaireController::class, 'update_partners']);

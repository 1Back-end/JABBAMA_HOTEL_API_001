<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('free_clients_restaurants', \App\Http\Controllers\FreeClientRestaurantController::class);
Route::patch('free_clients_restaurants/{uuid}/is_active', [\App\Http\Controllers\FreeClientRestaurantController::class, 'updateStatus']);
Route::post('free_clients_restaurants/{uuid}/update_free_clients_restaurants', [\App\Http\Controllers\FreeClientRestaurantController::class, 'update_free_clients_restaurants']);
Route::post('free_clients_restaurants/{uuid}/allocate_amount', [\App\Http\Controllers\FreeClientRestaurantController::class, 'allocateAmount']);

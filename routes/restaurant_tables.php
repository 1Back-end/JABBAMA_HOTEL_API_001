<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('restaurant_tables', \App\Http\Controllers\RestaurantTableController::class);
Route::patch('restaurant_tables/{uuid}/is_available', [\App\Http\Controllers\RestaurantTableController::class, 'update_status']);



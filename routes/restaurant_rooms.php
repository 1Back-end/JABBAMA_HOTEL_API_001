<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('restaurant_rooms', \App\Http\Controllers\RestaurantRoomController::class);
Route::patch('restaurant_rooms/{uuid}/is_active', [\App\Http\Controllers\RestaurantRoomController::class, 'update_status']);

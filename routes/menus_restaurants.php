<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('menus_restaurants', \App\Http\Controllers\MenuRestaurantController::class);
Route::patch('menus_restaurants/{uuid}/is_active', [\App\Http\Controllers\MenuRestaurantController::class, 'updateStatus']);
Route::post('menus_restaurants/{uuid}/update_menus_restaurants', [\App\Http\Controllers\MenuRestaurantController::class, 'update_menus']);
Route::get('/get_price', [\App\Http\Controllers\MenuRestaurantController::class, 'get_price_by_menus_and_clients']);
Route::get('/get_price_drink', [\App\Http\Controllers\MenuRestaurantController::class, 'get_price_by_drink_and_client']);
Route::get('/get_menu_is_confectioned', [\App\Http\Controllers\MenuRestaurantController::class, 'get_menu_is_confectioned']);
Route::get('/menus/{menu_uuid}/complements', [\App\Http\Controllers\MenuRestaurantController::class, 'get_complements_for_menu']);

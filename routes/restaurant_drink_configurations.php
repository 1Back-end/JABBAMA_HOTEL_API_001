<?php

use Illuminate\Support\Facades\Route;
Route::get('restaurant_drink_configurations/transformable_products', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'transformableProducts']);
Route::get('restaurant_drink_configurations/get_sall_drinks_for_restaurants', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'get_sall_drinks_for_restaurants']);
Route::get('restaurant_drink_configurations/transformable_products/{uuid}', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'getTransformableProductByUuid']);
Route::apiResource('restaurant_drink_configurations', \App\Http\Controllers\RestaurantDrinkConfigurationController::class);
Route::patch('restaurant_drink_configurations/{uuid}/is_active', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'update_status']);
Route::post('/compositions_drinks_orders/{drinks_restaurant_uuid}/store', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'upsert']);
Route::get('/compositions_drinks_orders/{drinks_restaurant_uuid}/show', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'get_compositions_drinks_by_uuid']);
Route::post('/restaurant_drink_configurations/transformable', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'storeTransformableByName']);
Route::post('/restaurant_drink_configurations/update_transformable/{uuid}', [\App\Http\Controllers\RestaurantDrinkConfigurationController::class, 'updateTransformableByName']);



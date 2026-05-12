<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('compositions_menus_orders', \App\Http\Controllers\MenuOrdersController::class);
Route::post('/compositions_menus_orders/{menus_restaurant_uuid}/store', [\App\Http\Controllers\MenuOrdersController::class, 'storeOrUpdateMenu']);
Route::get('/compositions_menus_orders/{menus_restaurant_uuid}/show', [\App\Http\Controllers\MenuOrdersController::class, 'showByMenu']);

<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('settings_restaurants', \App\Http\Controllers\SettingRestaurantController::class);
Route::patch('settings_restaurants/{uuid}/is_active', [\App\Http\Controllers\SettingRestaurantController::class, 'toggleActive']);
Route::get('/get_all_settings_restaurants', [\App\Http\Controllers\SettingRestaurantController::class, 'get_all_settings_restaurants']);
Route::get('/get_settings', [\App\Http\Controllers\SettingRestaurantController::class, 'getSetting']);

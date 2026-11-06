<?php
use Illuminate\Support\Facades\Route;
Route::apiResource('statistics/cooking', \App\Http\Controllers\RestaurantStatisticsController::class);
Route::get('statistics/drinks', [\App\Http\Controllers\RestaurantStatisticsController::class, 'index_drinks']);

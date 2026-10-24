<?php
use Illuminate\Support\Facades\Route;
Route::apiResource('statistics/cooking', \App\Http\Controllers\RestaurantStatisticsController::class);

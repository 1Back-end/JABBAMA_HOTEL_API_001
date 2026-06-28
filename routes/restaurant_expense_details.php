<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('restaurant_expense_details', \App\Http\Controllers\RestaurantExpenseController::class);
Route::put('restaurant_expense_details/{uuid}/cancel', [\App\Http\Controllers\RestaurantExpenseController::class, 'cancel']);

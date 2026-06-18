<?php

use App\Http\Controllers\RestaurantExpenseTypeController;
use Illuminate\Support\Facades\Route;
Route::apiResource('restaurant_expense_types', RestaurantExpenseTypeController::class);
Route::patch('restaurant_expense_types/{uuid}/is_active', [RestaurantExpenseTypeController::class, 'updateStatus']);

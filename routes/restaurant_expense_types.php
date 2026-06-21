<?php

use App\Http\Controllers\RestaurantExpenseTypeController;
use Illuminate\Support\Facades\Route;
Route::apiResource('restaurant_expense_types', RestaurantExpenseTypeController::class)->except(['show']);;
Route::patch('restaurant_expense_types/{uuid}/is_active', [RestaurantExpenseTypeController::class, 'updateStatus']);
Route::post('/restaurant_expense_types/store_sub_family', [RestaurantExpenseTypeController::class, 'storeSubFamily']);
Route::put('restaurant_expense_update_sub_family', [RestaurantExpenseTypeController::class, 'Update_Sub_Family']);
Route::get('/restaurant_expense_types/get_all_sub_families', [RestaurantExpenseTypeController::class, 'get_all_sub_families']);
Route::patch('restaurant_expense_types/{uuid}/is_active_family', [RestaurantExpenseTypeController::class, 'updateStatusFamilyAndSubFamily']);
Route::patch('/restaurant_expense_types/{uuid}/toggle', [RestaurantExpenseTypeController::class, 'toggleStatus']);

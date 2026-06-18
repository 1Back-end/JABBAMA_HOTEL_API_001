<?php

use App\Http\Controllers\SalesCategoryController;
use Illuminate\Support\Facades\Route;
Route::apiResource('sales_categories', SalesCategoryController::class);
Route::patch('sales_categories/{uuid}/is_active', [SalesCategoryController::class, 'updateStatus']);

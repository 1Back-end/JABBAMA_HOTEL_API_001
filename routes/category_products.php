<?php

use App\Http\Controllers\CategoryProductsController;
use Illuminate\Support\Facades\Route;
Route::apiResource('category_products', CategoryProductsController::class);
Route::patch('category_products/{uuid}/is_active', [CategoryProductsController::class, 'update_status']);

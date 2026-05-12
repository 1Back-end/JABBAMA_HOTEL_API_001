<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('menu_categories', \App\Http\Controllers\MenuCategoryController::class);
Route::patch('menu_categories/{uuid}/is_active', [\App\Http\Controllers\MenuCategoryController::class, 'update_status']);

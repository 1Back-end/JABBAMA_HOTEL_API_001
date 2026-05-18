<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
Route::apiResource('products', ProductController::class);
Route::patch('products/{uuid}/is_active', [ProductController::class, 'update_status']);
Route::post('products/{uuid}/update_products', [ProductController::class, 'update_products']);
Route::get('/products/inventory/print', [ProductController::class, 'export_products_by_points_uuid']);
Route::get('/products/{warehouse_uuid}/inventory/print', [ProductController::class, 'export_products_by_points_uuid']);
Route::get('/inventories/print/{warehouse_uuid?}', [ProductController::class, 'print_inventory_by_day_and_warehouses']);
Route::get('/products/{product_uuid}/last_sell_price', [ProductController::class, 'getLastSellPriceForProduct']);

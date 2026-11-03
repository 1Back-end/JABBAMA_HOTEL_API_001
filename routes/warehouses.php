<?php

use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;
Route::apiResource('warehouses', WarehouseController::class);
Route::patch('warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
Route::get('/exports/warehouse', [WarehouseController::class, 'export_warehouse']);
Route::get('warehouses/{uuid}/products', [WarehouseController::class, 'get_products_by_warehouse']);
Route::get('warehouses/{uuid}/get_managers_by_warehouse', [WarehouseController::class, 'get_managers_by_warehouse']);
Route::get("/exports/warehouses", [WarehouseController::class, 'export_warehouses']);
Route::patch('natures_warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
Route::get('product_used_for_warehouse_bar', [WarehouseController::class, 'product_used_for_warehouse_bar']);

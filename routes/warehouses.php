<?php

use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;
Route::apiResource('warehouses', WarehouseController::class);
Route::patch('warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
Route::get('get_all_warehouses_by_users', [WarehouseController::class, 'get_all_warehouses_by_users']);
Route::get('/exports/warehouse', [WarehouseController::class, 'export_warehouse']);
Route::get('warehouses/{uuid}/products', [WarehouseController::class, 'get_products_by_warehouse']);
Route::get('warehouses/{uuid}/get_products_by_warehouse_is_used_for_restaurant', [WarehouseController::class, 'get_products_by_warehouse_is_used_for_restaurant']);
Route::get('product_used_for_warehouse_bar', [WarehouseController::class, 'get_products_bar_points']);
Route::get('warehouses/{uuid}/get_products_by_warehouse_is_bar_warehouse', [WarehouseController::class, 'get_products_by_warehouse_is_bar_warehouse']);
Route::get('warehouses/{uuid}/get_managers_by_warehouse', [WarehouseController::class, 'get_managers_by_warehouse']);
Route::get('/warehouses/{pointUuid}/inventory/export', [WarehouseController::class, 'export_inventory_by_warehouse']);
Route::get('/warehouses/inventory/export', [WarehouseController::class, 'export_inventory_by_warehouse']);
Route::get('/warehouses/{point_uuid}/inventory/print', [WarehouseController::class, 'print_inventory_by_warehouse']);
Route::get("/exports/warehouses", [WarehouseController::class, 'export_warehouses']);
Route::patch('natures_warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
Route::get('get_warehouses_is_used_for_restaurant', [WarehouseController::class, 'get_warehouses_is_used_for_restaurant']);
Route::get('get_warehouses_is_bar_warehouse', [WarehouseController::class, 'get_warehouses_is_bar_warehouse']);
Route::get('get_warehouses_is_used_for_drinks_transformation', [WarehouseController::class, 'get_warehouses_is_used_for_drinks_transformation']);
Route::get('get_warehouses_is_drinks_or_is_cuisine', [WarehouseController::class, 'get_warehouses_is_drinks_or_is_cuisine']);


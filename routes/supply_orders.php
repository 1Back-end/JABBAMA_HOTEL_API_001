<?php

use App\Http\Controllers\SupplyController;
use Illuminate\Support\Facades\Route;
Route::apiResource('supply_orders', SupplyController::class);
Route::post('update_supplies/{uuid}/update_supplies', [SupplyController::class, 'update_supplies']);
Route::patch('supply_orders/{uuid}/reject_supply_by_super_admin', [SupplyController::class, 'reject_supply_by_super_admin']);
Route::patch('supply_orders/{uuid}/rejected_supplies', [SupplyController::class, 'rejected_supplies']);
Route::patch('supply_orders/{uuid}/validate_supply', [SupplyController::class, 'validate_supply']);
Route::get('supply_orders/{uuid}/print_supplies', [SupplyController::class, 'print_supplies']);
Route::patch('supply_orders/{uuid}/cancel_supply', [SupplyController::class, 'cancel_supply']);
Route::patch('supply_orders/{uuid}/transfer_supply', [SupplyController::class, 'transfer_supply']);
Route::get("/exports/supply", [SupplyController::class, 'export_supply']);
Route::get('supply_actions', [\App\Http\Controllers\SupplyController::class, 'SupplyStatus']);

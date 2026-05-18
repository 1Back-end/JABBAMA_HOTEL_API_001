<?php

use App\Http\Controllers\PurchaseOrderController;
use Illuminate\Support\Facades\Route;
Route::apiResource('orders', PurchaseOrderController::class);
Route::post('orders/{uuid}/update_orders', [PurchaseOrderController::class, 'update_orders']);
Route::patch('orders/{uuid}/cancel_orders', [PurchaseOrderController::class, 'cancel_orders']);
Route::patch('orders/{uuid}/cancel_orders_by_admin', [PurchaseOrderController::class, 'cancel_orders_by_admin']);
Route::post('orders/{uuid}/rejected_orders', [PurchaseOrderController::class, 'rejected_orders']);
Route::post('orders/{uuid}/send_orders', [PurchaseOrderController::class, 'send_orders']);
Route::patch('orders/{uuid}/validate_orders', [PurchaseOrderController::class, 'validate_orders']);
Route::patch('orders/{uuid}/rejected_orders_by_admin', [PurchaseOrderController::class, 'rejected_orders_by_admin']);
Route::get('/exports/orders', [PurchaseOrderController::class, 'export_orders']);
Route::post('orders/{uuid}/create_parents_orders', [PurchaseOrderController::class, 'create_parents_orders']);
Route::put('orders/{uuid}/update_parents_orders', [PurchaseOrderController::class, 'update_parents_orders']);
Route::get('orders/{uuid}/print_orders', [PurchaseOrderController::class, 'print_orders']);
Route::get('orders/{uuid}/show_parents_orders', [PurchaseOrderController::class, 'show_parents_orders']);
Route::get('orders_actions', [\App\Http\Controllers\PurchaseOrderController::class, 'PurchaseOrdersStatus']);
Route::get('get_validated_and_partial_validated_orders', [\App\Http\Controllers\PurchaseOrderController::class, 'get_validated_and_partial_validated_orders']);

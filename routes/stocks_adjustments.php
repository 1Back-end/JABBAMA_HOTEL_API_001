<?php
use Illuminate\Support\Facades\Route;
Route::apiResource('stocks_adjustments', \App\Http\Controllers\StockAdjustmentController::class);
Route::patch('stocks_adjustments/{uuid}/cancel_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'cancel_stock_adjustment']);
Route::patch('stocks_adjustments/{uuid}/validated_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'validated_stock_adjustment']);
Route::get('stocks_adjustments/{uuid}/print_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'print_stock_adjustment']);
Route::get('stocks_adjustments_actions', [\App\Http\Controllers\StockAdjustmentController::class, 'typeStockAdjustment']);
Route::get('/exports/export_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'export_stock_adjustment']);
Route::get('/stock-adjustments/print', [\App\Http\Controllers\StockAdjustmentController::class, 'print_stock_adjustments_by_action']);
Route::get("/prints/print_stock_adjustments", [\App\Http\Controllers\StockAdjustmentController::class, 'print_stock_adjustments']);

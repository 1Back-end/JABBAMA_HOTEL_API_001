<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('deductions_stocks', \App\Http\Controllers\StockDeductionController::class);
Route::get('deductions_stocks_actions', [\App\Http\Controllers\StockDeductionController::class, 'TypeStocksDeductionsStatus']);
Route::patch('deductions_stocks/{uuid}/cancel_deductions_stocks', [\App\Http\Controllers\StockDeductionController::class, 'cancel_deductions_stocks']);
Route::patch('deductions_stocks/{uuid}/validate_deductions_stocks', [\App\Http\Controllers\StockDeductionController::class, 'validated_deductions_stocks']);
Route::get("/exports/exports_stocks_deductions", [\App\Http\Controllers\StockDeductionController::class, 'export_stocks_deductions']);
Route::get('deductions_stocks/{uuid}/print_deductions_stocks', [\App\Http\Controllers\StockDeductionController::class, 'print_stocks_deductions']);

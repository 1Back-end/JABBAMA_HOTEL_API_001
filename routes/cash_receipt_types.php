<?php

use App\Http\Controllers\CashReceiptTypeController;
use Illuminate\Support\Facades\Route;
Route::apiResource('cash_receipt_types', CashReceiptTypeController::class);
Route::patch('cash_receipt_types/{uuid}/is_active', [CashReceiptTypeController::class, 'updateStatus']);
Route::post('cash_receipt_types/store_family', [CashReceiptTypeController::class, 'store_family']);


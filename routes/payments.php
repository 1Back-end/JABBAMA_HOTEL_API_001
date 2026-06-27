<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('payments_and_regulations', \App\Http\Controllers\PaymentController::class)->except(['show']);
Route::delete('payments_and_regulations/{uuid}/cancel', [\App\Http\Controllers\PaymentController::class, 'cancel']);
Route::get('payments_and_regulations/cash_register_sheet', [\App\Http\Controllers\PaymentController::class, 'get_cash_register_sheet']);

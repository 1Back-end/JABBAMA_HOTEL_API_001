<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('payments_and_regulations', \App\Http\Controllers\PaymentController::class)->except(['show']);
Route::delete('payments_and_regulations/{uuid}/cancel', [\App\Http\Controllers\PaymentController::class, 'cancel']);
Route::get('payments_and_regulations/cash_register_sheet', [\App\Http\Controllers\PaymentController::class, 'get_cash_register_sheet']);
Route::get('payments_and_regulations/{uuid}/show_by_uuid', [\App\Http\Controllers\PaymentController::class, 'show_payments_by_uuid']);
Route::get('payments_and_regulations/global_cashflow/today', [\App\Http\Controllers\PaymentController::class, 'show_global_cashflow_today']);
Route::post('payments_and_regulations/store_recouvrements', [\App\Http\Controllers\PaymentController::class, 'store_recouvrements']);
Route::delete('payments_and_regulations/{uuid}/cancel_recouvrements', [\App\Http\Controllers\PaymentController::class, 'cancel_recouvrements']);

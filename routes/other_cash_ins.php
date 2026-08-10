<?php
use Illuminate\Support\Facades\Route;
Route::apiResource('other_cash_ins', \App\Http\Controllers\OtherCashInController::class);
Route::post('other_cash_ins/{uuid}/update_other_cash_ins', [\App\Http\Controllers\OtherCashInController::class, 'update_other_cash_ins']);
Route::patch('other_cash_ins/{uuid}/cancel', [\App\Http\Controllers\OtherCashInController::class, 'cancel']);


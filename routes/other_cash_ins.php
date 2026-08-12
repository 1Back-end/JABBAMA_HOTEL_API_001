<?php
use Illuminate\Support\Facades\Route;
Route::apiResource('other_cash_ins', \App\Http\Controllers\OtherCashInController::class);
Route::post('other_cash_ins/{uuid}/update_other_cash_ins', [\App\Http\Controllers\OtherCashInController::class, 'update_other_cash_ins']);
Route::patch('other_cash_ins/{uuid}/cancel', [\App\Http\Controllers\OtherCashInController::class, 'cancel']);
Route::post('other_cash_ins/cancel_group', [\App\Http\Controllers\OtherCashInController::class, 'cancelGroup']);
Route::post('other_cash_ins/cancel_family', [\App\Http\Controllers\OtherCashInController::class, 'cancelFamily']);


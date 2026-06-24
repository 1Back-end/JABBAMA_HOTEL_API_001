<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('payments_and_regulations', \App\Http\Controllers\PaymentController::class);
Route::delete('payments_and_regulations/{uuid}/delete', [\App\Http\Controllers\PaymentController::class, 'destroyRegulation']);

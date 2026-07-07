<?php

use App\Http\Controllers\ClientAllocationController;
use Illuminate\Support\Facades\Route;
Route::apiResource('client_allocations', ClientAllocationController::class);
Route::post('client_allocations/{uuid}/refund_all', [ClientAllocationController::class, 'refundAll']);

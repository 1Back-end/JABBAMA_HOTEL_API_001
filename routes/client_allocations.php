<?php

use App\Http\Controllers\ClientAllocationController;
use Illuminate\Support\Facades\Route;
Route::apiResource('client_allocations', ClientAllocationController::class);

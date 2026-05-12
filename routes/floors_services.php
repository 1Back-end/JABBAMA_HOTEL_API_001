<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('floors_services', \App\Http\Controllers\FloorController::class);
Route::patch('floors_services/{uuid}/is_active', [\App\Http\Controllers\FloorController::class, 'updateStatus']);

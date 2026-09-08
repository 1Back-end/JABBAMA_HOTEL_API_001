<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('operational_monitoring', \App\Http\Controllers\OperationalMonitoringController::class)->except(['show']);
Route::get('operational_monitoring/pdf', [\App\Http\Controllers\OperationalMonitoringController::class, 'print_situations_sheet']);

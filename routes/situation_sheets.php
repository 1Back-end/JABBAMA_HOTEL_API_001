<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('situation_sheets', \App\Http\Controllers\MainCouranteController::class);
Route::get('situations_sheet/pdf', [\App\Http\Controllers\MainCouranteController::class, 'print_situations_sheet']);

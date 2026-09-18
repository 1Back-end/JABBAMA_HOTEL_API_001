<?php

use Illuminate\Support\Facades\Route;
Route::get('data/main_courante/pdf', [\App\Http\Controllers\DataController::class, 'exportMainCourantePdf']);
Route::get('data/main_courante_complete', [\App\Http\Controllers\DataController::class, 'getCompleteMainCouranteData']);

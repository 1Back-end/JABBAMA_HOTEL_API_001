<?php
use Illuminate\Support\Facades\Route;
Route::apiResource('other_reports', \App\Http\Controllers\OthersReportsController::class);
Route::get('/drinks/cost-analysis', [\App\Http\Controllers\OthersReportsController::class, 'indexDrinks']);

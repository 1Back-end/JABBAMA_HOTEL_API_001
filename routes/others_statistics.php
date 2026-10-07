<?php
use Illuminate\Support\Facades\Route;
Route::get('/restaurant/statistics/menus', [\App\Http\Controllers\OthersStatisticsController::class, 'menusIndex']);
Route::get('/restaurant/statistics/drinks', [\App\Http\Controllers\OthersStatisticsController::class, 'drinksIndex']);
Route::get('/restaurant/menus/top_selling', [\App\Http\Controllers\OthersStatisticsController::class, 'topSellingItemsIndex']);
Route::get('/restaurant/menus/defective_stats', [\App\Http\Controllers\OthersStatisticsController::class, 'get_defective_statistics']);

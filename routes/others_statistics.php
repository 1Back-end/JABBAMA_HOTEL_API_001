<?php
use Illuminate\Support\Facades\Route;
Route::get('/restaurant/statistics/menus', [\App\Http\Controllers\OthersStatisticsController::class, 'menusIndex']);
Route::get('/restaurant/statistics/drinks', [\App\Http\Controllers\OthersStatisticsController::class, 'drinksIndex']);
Route::get('/restaurant/print_menusIndex_and_drinksIndex', [\App\Http\Controllers\OthersStatisticsController::class, 'print_menusIndex_and_drinksIndex']);
Route::get('/restaurant/menus/top_selling', [\App\Http\Controllers\OthersStatisticsController::class, 'topSellingItemsIndex']);
Route::get('/restaurant/print_topSellingItemsIndex', [\App\Http\Controllers\OthersStatisticsController::class, 'print_topSellingItemsIndex']);
Route::get('/restaurant/menus/defective_stats', [\App\Http\Controllers\OthersStatisticsController::class, 'get_defective_statistics']);
Route::get('/restaurant/print_get_defective_statistics', [\App\Http\Controllers\OthersStatisticsController::class, 'print_get_defective_statistics']);
Route::get('/restaurant/sales/client_history_detailed', [\App\Http\Controllers\OthersStatisticsController::class, 'clientConsumptionHistory']);
Route::get('/restaurant/print_clientConsumptionHistory', [\App\Http\Controllers\OthersStatisticsController::class, 'print_clientConsumptionHistory']);

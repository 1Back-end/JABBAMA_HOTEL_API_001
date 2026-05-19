<?php
use Illuminate\Support\Facades\Route;
Route::get('/statistics/products/top_consumed', [\App\Http\Controllers\StatisticsController::class, 'topConsumedProducts']);
Route::get('/statistics/products_percentage', [\App\Http\Controllers\StatisticsController::class, 'get_statistics_by_products']);
Route::get('/purchase_orders/total_by_status', [\App\Http\Controllers\StatisticsController::class, 'suppliesOrders']);
Route::get('/supply/total_by_status', [\App\Http\Controllers\StatisticsController::class, 'suppliesJournal']);
Route::get('/stocks_adjustments_actions/total_by_stocks_adjustments', [\App\Http\Controllers\StatisticsController::class, 'StockAdjustmentsJournal']);
Route::get('/print_suppliesOrders', [\App\Http\Controllers\StatisticsController::class, 'print_suppliesOrders']);
Route::get('/print_suppliesJournal', [\App\Http\Controllers\StatisticsController::class, 'print_suppliesJournal']);
Route::get('/products/{productUuid}/price_variation', [\App\Http\Controllers\StatisticsController::class, 'get_statictic_by_variation_supply_price']);
Route::get('/products/{productUuid}/quantity_variation', [\App\Http\Controllers\StatisticsController::class, 'get_statitics_by_variation_quantity']);
Route::get('/products/{productUuid}/avaries', [\App\Http\Controllers\StatisticsController::class, 'get_statistics_by_avaries_products']);
Route::get('/dashboard/print_all_data', [\App\Http\Controllers\StatisticsController::class, 'print_all_data_for_dashboard']);
Route::get('/statistics/top_consumed_products',[\App\Http\Controllers\StatisticsController::class, 'topConsumedProducts']);

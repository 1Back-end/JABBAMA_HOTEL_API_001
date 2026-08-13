<?php

use Illuminate\Support\Facades\Route;
Route::get('data/restaurant_gle', [\App\Http\Controllers\DataController::class, 'get_GLE_for_main_courante']);
Route::get('data/restaurant_encaissement', [\App\Http\Controllers\DataController::class, 'get_encaissement_for_main_courante']);
Route::get('data/restaurant_not_paid', [\App\Http\Controllers\DataController::class, 'get_not_paid_for_main_courante']);
Route::get('data/restaurant_sales_category_totals', [\App\Http\Controllers\DataController::class, 'get_sales_category_totals_for_main_courante']);
Route::get('data/restaurant_bar_total', [\App\Http\Controllers\DataController::class, 'get_restaurant_bar_total']);
Route::get('data/restaurant_divers_total', [\App\Http\Controllers\DataController::class, 'get_restaurant_total_by_client_type']);
Route::get('data/count_restaurant_sales_category_totals', [\App\Http\Controllers\DataController::class, 'get_count_sales_category_totals_for_main_courante']);
Route::get('data/restaurant_bar_count', [\App\Http\Controllers\DataController::class, 'get_restaurant_bar_count']);
Route::get('data/restaurant_count_by_client_type', [\App\Http\Controllers\DataController::class, 'get_restaurant_count_by_client_type']);

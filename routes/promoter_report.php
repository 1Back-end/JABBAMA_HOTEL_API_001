<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromoterReportController;
use Illuminate\Support\Facades\Route;
Route::apiResource('promoter_kpi_card', PromoterReportController::class);
Route::get('/promoter_restaurant_summary', [PromoterReportController::class, 'getRestaurantSummary']);
Route::get('/promoter_bar_summary', [PromoterReportController::class, 'getBarSummary']);
Route::get('/promoter_other_incomes', [PromoterReportController::class, 'getAutresEncaissements']);
Route::get('/promoter_pdg_expenses', [PromoterReportController::class, 'getPdgExpenses']);
Route::get('/summary_payment_methods', [PromoterReportController::class, 'getPaymentMethodsSummary']);
Route::get('/promoter_debtors_summary', [PromoterReportController::class, 'getDebtorsSummary']);
Route::get('/detailed_sales_summary_restaurant', [PromoterReportController::class, 'getDetailedSalesSummary']);

<?php

use App\Http\Controllers\StockPerformanceCriterionController;
use Illuminate\Support\Facades\Route;
Route::apiResource('stock_performance_criteria', StockPerformanceCriterionController::class);
Route::patch('stock_performance_criteria/{uuid}/is_active', [StockPerformanceCriterionController::class, 'updateStatus']);

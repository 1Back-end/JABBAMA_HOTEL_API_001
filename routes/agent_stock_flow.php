<?php

use App\Http\Controllers\CategoryProductsController;
use Illuminate\Support\Facades\Route;
Route::apiResource('reports/agent_stock_flow', \App\Http\Controllers\AgentStockFlowController::class);
Route::get('/reports/bar_distribution_flow_rate', [\App\Http\Controllers\AgentStockFlowController::class, 'indexBarDistribution']);
Route::get('/reports/bar_transformation_flow_rate', [\App\Http\Controllers\AgentStockFlowController::class, 'indexBarTransformation']);
Route::get('/reports/print', [\App\Http\Controllers\AgentStockFlowController::class, 'print']);

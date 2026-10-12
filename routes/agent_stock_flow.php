<?php

use App\Http\Controllers\CategoryProductsController;
use Illuminate\Support\Facades\Route;
Route::apiResource('reports/agent_stock_flow', \App\Http\Controllers\AgentStockFlowController::class);

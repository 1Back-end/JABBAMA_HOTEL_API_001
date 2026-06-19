<?php

use App\Http\Controllers\CashCollectionFamilyController;
use Illuminate\Support\Facades\Route;
Route::apiResource('cash_collection_families', CashCollectionFamilyController::class);
Route::patch('cash_collection_families/{uuid}/is_active', [CashCollectionFamilyController::class, 'updateStatus']);
Route::post('sub_cash_collection_families/insert_or_update', [CashCollectionFamilyController::class, 'insertOrUpdate']);


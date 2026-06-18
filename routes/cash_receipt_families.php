<?php

use App\Http\Controllers\CashReceiptFamilyController;
use Illuminate\Support\Facades\Route;
Route::apiResource('cash_receipt_families', CashReceiptFamilyController::class);

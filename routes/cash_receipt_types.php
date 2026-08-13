<?php

use App\Http\Controllers\CashReceiptTypeController;
use Illuminate\Support\Facades\Route;
Route::apiResource('cash_receipt_types', CashReceiptTypeController::class);
Route::patch('cash_receipt_types/{uuid}/is_active', [CashReceiptTypeController::class, 'updateStatus']);
Route::post('cash_receipt_types/store_family', [CashReceiptTypeController::class, 'store_family']);
Route::post('/cash_receipt_types/update_family', [CashReceiptTypeController::class, 'update_family']);
Route::post('cash_receipt_types/store_sub_family', [CashReceiptTypeController::class, 'store_sub_family']);
Route::put('update_sub_family', [CashReceiptTypeController::class, 'Update_Sub_Family']);
Route::patch('/cash_receipt_families/{uuid}/toggle', [CashReceiptTypeController::class, 'toggleStatus']);
Route::get('families_and_sub_families', [CashReceiptTypeController::class, 'get_all_families_and_sub_families']);
Route::patch('sub_families/{uuid}/is_active', [CashReceiptTypeController::class, 'updateStatusFamilyAndSubFamily']);
Route::get('/cash_receipt_families/filtered', [CashReceiptTypeController::class, 'getFamiliesGrouped']);

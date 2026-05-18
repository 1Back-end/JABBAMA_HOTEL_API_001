<?php

use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;
Route::apiResource("suppliers", SupplierController::class);
Route::patch('suppliers/{uuid}/is_active', [SupplierController::class, 'update_status']);
Route::post('suppliers/send_user_code_otp', [SupplierController::class, 'send_user_code_otp']);
Route::get('/exports/suppliers', [SupplierController::class, 'export_suppliers']);


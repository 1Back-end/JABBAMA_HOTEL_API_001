<?php
use App\Http\Controllers\Admin\UserController;

use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;


Route::middleware(['activity'])->group(function () {

    require __DIR__ . '/auth.php';
    require __DIR__ . '/authorization.php';
    require __DIR__ . '/admin.php';

    Route::middleware(['auth:sanctum', 'user.change_password', 'check.permission'])->group(function () {

        Route::apiResource("suppliers", SupplierController::class);
        Route::patch('suppliers/{uuid}/is_active', [SupplierController::class, 'update_status']);
        Route::post('suppliers/send_user_code_otp', [SupplierController::class, 'send_user_code_otp']);
        Route::get('suppliers/export_suppliers', [SupplierController::class, 'export_suppliers']);


    });
});

<?php
use App\Http\Controllers\Admin\UserController;

use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\CategoryProductsController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;


Route::middleware(['activity'])->group(function () {

    require __DIR__ . '/auth.php';
    require __DIR__ . '/authorization.php';
    require __DIR__ . '/admin.php';

    Route::middleware(['auth:sanctum', 'user.change_password', 'check.permission'])->group(function () {

        Route::apiResource("suppliers", SupplierController::class);
        Route::patch('suppliers/{uuid}/is_active', [SupplierController::class, 'update_status']);
        Route::post('suppliers/send_user_code_otp', [SupplierController::class, 'send_user_code_otp']);

        Route::apiResource("units", UnitController::class);
        Route::patch('units/{uuid}/is_active', [UnitController::class, 'update_status']);

        Route::apiResource('category_products', CategoryProductsController::class);
        Route::patch('category_products/{uuid}/is_active', [CategoryProductsController::class, 'update_status']);!

        Route::apiResource('warehouses', WarehouseController::class);
        Route::patch('warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
        Route::apiResource('products', ProductController::class);
        Route::patch('products/{uuid}/is_active', [ProductController::class, 'update_status']);


    });
});

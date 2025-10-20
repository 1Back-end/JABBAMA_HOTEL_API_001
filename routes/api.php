<?php
use App\Http\Controllers\Admin\UserController;

use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\CategoryProductsController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\NatureEntrepotController;
use App\Http\Controllers\PurchaseOrderController;
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
        Route::patch('category_products/{uuid}/is_active', [CategoryProductsController::class, 'update_status']);

        Route::apiResource('warehouses', WarehouseController::class);
        Route::patch('warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
        Route::apiResource('products', ProductController::class);
        Route::patch('products/{uuid}/is_active', [ProductController::class, 'update_status']);
        Route::post('products/{uuid}/update_products', [ProductController::class, 'update_products']);

        Route::apiResource('subcategories', SubCategoryController::class);
        Route::patch('subcategories/{uuid}/is_active', [SubCategoryController::class, 'update_status']);
        Route::get('subcategories/by_category/{category_uuid}', [CategoryProductsController::class, 'get_by_category']);

        Route::apiResource('natures_warehouses', NatureEntrepotController::class);
        Route::patch('natures_warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);

        Route::apiResource('orders', PurchaseOrderController::class);
        Route::patch('orders/{uuid}/status', [PurchaseOrderController::class, 'update_status']);
        Route::post('orders/{uuid}/update_orders', [PurchaseOrderController::class, 'update_orders']);
        Route::patch('orders/{uuid}/cancel_orders', [PurchaseOrderController::class, 'cancel_orders']);
        Route::post('orders/{uuid}/rejected_orders', [PurchaseOrderController::class, 'rejected_orders']);
        Route::post('orders/{uuid}/send_orders', [PurchaseOrderController::class, 'send_orders']);





    });
});

<?php
use App\Http\Controllers\Admin\UserController;

use App\Http\Controllers\Authorization\PermissionController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplyController;
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

        Route::get("/get_users_where_role_is_gestionnaire_stock",[UserController::class,'get_users_where_role_is_gestionnaire_stock']);
        Route::get('/permissions_by_category', [PermissionController::class, 'permissionsByCategory']);


        Route::apiResource("suppliers", SupplierController::class);
        Route::patch('suppliers/{uuid}/is_active', [SupplierController::class, 'update_status']);
        Route::post('suppliers/send_user_code_otp', [SupplierController::class, 'send_user_code_otp']);
        Route::get('/exports/suppliers', [SupplierController::class, 'export_suppliers']);


        Route::apiResource("units", UnitController::class);
        Route::patch('units/{uuid}/is_active', [UnitController::class, 'update_status']);

        Route::apiResource('category_products', CategoryProductsController::class);
        Route::patch('category_products/{uuid}/is_active', [CategoryProductsController::class, 'update_status']);

        Route::apiResource('warehouses', WarehouseController::class);
        Route::patch('warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);
        Route::get('get_all_warehouses_by_users', [WarehouseController::class, 'get_all_warehouses_by_users']);
        Route::get('/exports/warehouse', [WarehouseController::class, 'export_warehouse']);


        Route::apiResource('products', ProductController::class);
        Route::patch('products/{uuid}/is_active', [ProductController::class, 'update_status']);
        Route::post('products/{uuid}/update_products', [ProductController::class, 'update_products']);
        Route::get('/products/inventory/print', [ProductController::class, 'export_products_by_points_uuid']);
        Route::get('/products/{warehouse_uuid}/inventory/print', [ProductController::class, 'export_products_by_points_uuid']);
        Route::get('/inventories/print/{warehouse_uuid?}', [ProductController::class, 'print_inventory_by_day_and_warehouses']);



        Route::get('warehouses/{uuid}/products', [WarehouseController::class, 'get_products_by_warehouse']);
        Route::get('warehouses/{uuid}/get_managers_by_warehouse', [WarehouseController::class, 'get_managers_by_warehouse']);
        Route::get('/warehouses/{pointUuid}/inventory/export', [WarehouseController::class, 'export_inventory_by_warehouse']);
        Route::get('/warehouses/inventory/export', [WarehouseController::class, 'export_inventory_by_warehouse']);
        Route::get('/warehouses/{point_uuid}/inventory/print', [WarehouseController::class, 'print_inventory_by_warehouse']);
        Route::get("/exports/warehouses", [WarehouseController::class, 'export_warehouses']);

        Route::apiResource('subcategories', SubCategoryController::class);
        Route::patch('subcategories/{uuid}/is_active', [SubCategoryController::class, 'update_status']);
        Route::get('subcategories/by_category/{category_uuid}', [CategoryProductsController::class, 'get_by_category']);

        Route::apiResource('natures_warehouses', NatureEntrepotController::class);
        Route::patch('natures_warehouses/{uuid}/is_active', [WarehouseController::class, 'update_status']);

        Route::apiResource('orders', PurchaseOrderController::class);
        Route::post('orders/{uuid}/update_orders', [PurchaseOrderController::class, 'update_orders']);
        Route::patch('orders/{uuid}/cancel_orders', [PurchaseOrderController::class, 'cancel_orders']);
        Route::patch('orders/{uuid}/cancel_orders_by_admin', [PurchaseOrderController::class, 'cancel_orders_by_admin']);
        Route::post('orders/{uuid}/rejected_orders', [PurchaseOrderController::class, 'rejected_orders']);
        Route::post('orders/{uuid}/send_orders', [PurchaseOrderController::class, 'send_orders']);
        Route::patch('orders/{uuid}/validate_orders', [PurchaseOrderController::class, 'validate_orders']);
        Route::patch('orders/{uuid}/rejected_orders_by_admin', [PurchaseOrderController::class, 'rejected_orders_by_admin']);
        Route::get('/exports/orders', [PurchaseOrderController::class, 'export_orders']);
        Route::post('orders/{uuid}/create_parents_orders', [PurchaseOrderController::class, 'create_parents_orders']);
        Route::put('orders/{uuid}/update_parents_orders', [PurchaseOrderController::class, 'update_parents_orders']);
        Route::get('orders/{uuid}/print_orders', [PurchaseOrderController::class, 'print_orders']);
        Route::get('orders/{uuid}/show_parents_orders', [PurchaseOrderController::class, 'show_parents_orders']);
        Route::get('orders_actions', [\App\Http\Controllers\PurchaseOrderController::class, 'PurchaseOrdersStatus']);
        Route::get('get_validated_and_partial_validated_orders', [\App\Http\Controllers\PurchaseOrderController::class, 'get_validated_and_partial_validated_orders']);

        Route::apiResource('supply_orders', SupplyController::class);
        Route::post('update_supplies/{uuid}/update_supplies', [SupplyController::class, 'update_supplies']);
        Route::patch('supply_orders/{uuid}/reject_supply_by_super_admin', [SupplyController::class, 'reject_supply_by_super_admin']);
        Route::patch('supply_orders/{uuid}/rejected_supplies', [SupplyController::class, 'rejected_supplies']);
        Route::patch('supply_orders/{uuid}/validate_supply', [SupplyController::class, 'validate_supply']);
        Route::patch('supply_orders/{uuid}/open_supply', [SupplyController::class, 'open_supply']);
        Route::get('supply_orders/{uuid}/print_supplies', [SupplyController::class, 'print_supplies']);
        Route::patch('supply_orders/{uuid}/cancel_supply', [SupplyController::class, 'cancel_supply']);
        Route::patch('supply_orders/{uuid}/transfer_supply', [SupplyController::class, 'transfer_supply']);
        Route::get("/exports/supply", [SupplyController::class, 'export_supply']);
        Route::get('supply_actions', [\App\Http\Controllers\SupplyController::class, 'SupplyStatus']);

        Route::apiResource('passations', \App\Http\Controllers\PassationController::class);
        Route::patch('passations/{uuid}/cancel_passations', [\App\Http\Controllers\PassationController::class, 'cancel_passations']);
        Route::patch('passations/{uuid}/validate_passations', [\App\Http\Controllers\PassationController::class, 'validate_passations']);
        Route::patch('passations/{uuid}/update_validation', [\App\Http\Controllers\PassationController::class, 'update_passation_validation']);
        Route::patch('passations/{uuid}/reject_passations', [\App\Http\Controllers\PassationController::class, 'reject_passations']);
        Route::get('passations/{uuid}/print_passations', [\App\Http\Controllers\PassationController::class, 'print_passations']);
        Route::patch('passations/{uuid}/validate_passations_by_admin', [\App\Http\Controllers\PassationController::class, 'validate_passations_by_admin']);
        Route::get('/passations/inventory/print', [\App\Http\Controllers\PassationController::class, 'print_passations_by_agents_uuid']);
        Route::get("/exports/passations_stocks", [\App\Http\Controllers\PassationController::class, 'export_passations_stocks']);
        Route::get("/prints/passations_stocks", [\App\Http\Controllers\PassationController::class, 'print_passations_by_filter']);


        Route::apiResource('stocks_adjustments', \App\Http\Controllers\StockAdjustmentController::class);
        Route::patch('stocks_adjustments/{uuid}/cancel_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'cancel_stock_adjustment']);
        Route::patch('stocks_adjustments/{uuid}/validated_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'validated_stock_adjustment']);
        Route::get('stocks_adjustments/{uuid}/print_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'print_stock_adjustment']);
        Route::get('stocks_adjustments_actions', [\App\Http\Controllers\StockAdjustmentController::class, 'typeStockAdjustment']);
        Route::get('/exports/export_stock_adjustment', [\App\Http\Controllers\StockAdjustmentController::class, 'export_stock_adjustment']);
        Route::get('/stock-adjustments/print', [\App\Http\Controllers\StockAdjustmentController::class, 'print_stock_adjustments_by_action']);
        Route::get("/prints/print_stock_adjustments", [\App\Http\Controllers\StockAdjustmentController::class, 'print_stock_adjustments']);

        Route::get('/statistics/products/top_consumed', [\App\Http\Controllers\StatisticsController::class, 'topConsumedProducts']);
        Route::get('/statistics/products_percentage', [\App\Http\Controllers\StatisticsController::class, 'get_statistics_by_products']);
        Route::get('/purchase_orders/total_by_status', [\App\Http\Controllers\StatisticsController::class, 'suppliesOrders']);
        Route::get('/supply/total_by_status', [\App\Http\Controllers\StatisticsController::class, 'suppliesJournal']);
        Route::get('/stocks_adjustments_actions/total_by_stocks_adjustments', [\App\Http\Controllers\StatisticsController::class, 'StockAdjustmentsJournal']);
        Route::get('/print_suppliesOrders', [\App\Http\Controllers\StatisticsController::class, 'print_suppliesOrders']);
        Route::get('/print_suppliesJournal', [\App\Http\Controllers\StatisticsController::class, 'print_suppliesJournal']);
        Route::get('/products/{productUuid}/price_variation', [\App\Http\Controllers\StatisticsController::class, 'get_statictic_by_variation_supply_price']);
        Route::get('/products/{productUuid}/quantity_variation', [\App\Http\Controllers\StatisticsController::class, 'get_statitics_by_variation_quantity']);
        Route::get('/products/{productUuid}/avaries', [\App\Http\Controllers\StatisticsController::class, 'get_statistics_by_avaries_products']);
        Route::get('/dashboard/print_all_data', [\App\Http\Controllers\StatisticsController::class, 'print_all_data_for_dashboard']);
        Route::get('/statistics/top_consumed_products',[\App\Http\Controllers\StatisticsController::class, 'topConsumedProducts']);



        Route::post('/permissions/sync_permissions', [\App\Http\Controllers\PermissionAdminController::class, 'sync_permissions']);
        Route::post('/migrations/run_migrations', [\App\Http\Controllers\MigrationController::class, 'run_migrations']);

        Route::apiResource('deductions_stocks', \App\Http\Controllers\StockDeductionController::class);
        Route::get('deductions_stocks_actions', [\App\Http\Controllers\StockDeductionController::class, 'TypeStocksDeductionsStatus']);
        Route::patch('deductions_stocks/{uuid}/cancel_deductions_stocks', [\App\Http\Controllers\StockDeductionController::class, 'cancel_deductions_stocks']);
        Route::patch('deductions_stocks/{uuid}/validate_deductions_stocks', [\App\Http\Controllers\StockDeductionController::class, 'validated_deductions_stocks']);
        Route::get("/exports/exports_stocks_deductions", [\App\Http\Controllers\StockDeductionController::class, 'export_stocks_deductions']);
        Route::get('deductions_stocks/{uuid}/print_deductions_stocks', [\App\Http\Controllers\StockDeductionController::class, 'print_stocks_deductions']);





    });
});

<?php
use App\Http\Controllers\Admin\UserController;

use App\Http\Controllers\Authorization\PermissionController;
use App\Http\Controllers\CategoryProductsController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\NatureEntrepotController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {

    Route::middleware(['user.change_password', 'check.permission','response.time'])->group(function () {
        require __DIR__ . '/authorization.php';
        require __DIR__ . '/admin.php';

        Route::post('/restaurant/clean_abandoned', function () {
            try {
                Artisan::call('restaurant:clean-abandoned');
                return response()->json([
                    'status' => 'success',
                    'result' => trim(Artisan::output())
                ]);
            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        });


        Route::get("/get_users_where_role_is_gestionnaire_stock",[UserController::class,'get_users_where_role_is_gestionnaire_stock']);
        Route::get("/get_users_where_role_is_cuisinier",[UserController::class,'get_users_where_role_is_cuisinier']);
        Route::get('/permissions_by_category', [PermissionController::class, 'permissionsByCategory']);
        Route::apiResource('subcategories', SubCategoryController::class);
        Route::patch('subcategories/{uuid}/is_active', [SubCategoryController::class, 'update_status']);
        Route::get('subcategories/by_category/{category_uuid}', [CategoryProductsController::class, 'get_by_category']);
        Route::apiResource('natures_warehouses', NatureEntrepotController::class);
        Route::post('/permissions/sync_permissions', [\App\Http\Controllers\PermissionAdminController::class, 'sync_permissions']);
        Route::post('/migrations/run_migrations', [\App\Http\Controllers\MigrationController::class, 'run_migrations']);


        /*
         |--------------------------------------------------------------------------
         | GESTION DES STOCKS
         |---------------------------------------------------------------------------
       */
        require __DIR__ . '/suppliers.php';
        require __DIR__ . '/units.php';
        require __DIR__ . '/products.php';
        require __DIR__ . '/category_products.php';
        require __DIR__ . '/warehouses.php';
        require __DIR__ . '/orders.php';
        require __DIR__ . '/supply_orders.php';
        require __DIR__ . '/passations.php';
        require __DIR__ . '/stocks_adjustments.php';
        require __DIR__ . '/deductions_stocks.php';
        require __DIR__ . '/statistics.php';

        /*
         |--------------------------------------------------------------------------
         | GESTION DU RESTAURANT
         |---------------------------------------------------------------------------
       */
        require __DIR__ . '/data.php';
        require __DIR__ . '/other_cash_ins.php';
        require __DIR__ . '/client_allocations.php';
        require __DIR__ . '/restaurant_expense_details.php';
        require __DIR__ . '/payments.php';
        require __DIR__ . '/sales_categories.php';
        require __DIR__ . '/restaurant_expense_types.php';
        require __DIR__ . '/cash_receipt_types.php';
        require __DIR__ . '/configurations_complements.php';
        require __DIR__.'/floors_services.php';
        require __DIR__.'/menus_restaurants.php';
        require __DIR__.'/restaurant_drink_configurations.php';
        require __DIR__.'/restaurant_tables.php';
        require __DIR__.'/menu_categories.php';
        require __DIR__.'/regulation_methods.php';
        require __DIR__.'/restaurant_partners.php';
        require __DIR__.'/free_clients_restaurants.php';
        require __DIR__.'/enums_data.php';
        require __DIR__.'/compositions_menus_orders.php';
        require __DIR__.'/orders_menu_restaurants.php';
        require __DIR__ . '/notifications.php';
        require __DIR__ . '/restaurant_rooms.php';
        require __DIR__ . '/settings_restaurants.php';
        require __DIR__ . '/modules_applications.php';
        require __DIR__ . '/countries.php';

    });
});
require __DIR__ . '/auth.php';

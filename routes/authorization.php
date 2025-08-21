<?php

use App\Http\Controllers\Authorization\MenuController;
use App\Http\Controllers\Authorization\PermissionController;
use App\Http\Controllers\Authorization\RoleController;

Route::middleware(['auth:sanctum', 'user.change_password', 'check.permission'])->group(function () {
    Route::controller(RoleController::class)->prefix('roles')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{role}', 'show');
        Route::put('/{role}/activate/{activate}', 'activate');
        Route::put('/{role}', 'update');
        Route::post('/{role}/permissions', 'assignRoleToPermission');
        Route::post('/{role}/permissions/{permission}/activate/{activate}', 'activateRoleToPermission');
        Route::post('/{role}/users', 'assignRoleToUser');
        Route::post('/{role}/users/{user}/activate/{activate}', 'activateRoleToUser');
    });

    Route::controller(PermissionController::class)->prefix('permissions')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{permission}', 'show');
        Route::put('/{permission}/activate/{activate}', 'activate');
        Route::put('/{permission}', 'update');
        Route::post('/{permission}/roles', 'assignPermissionToRole');
        Route::post('/{permission}/roles/{role}/activate/{activate}', 'activatePermissionToRole');
        Route::post('/users', 'assignPermissionToUser');
        Route::post('/{permission}/users/{user}/activate/{activate}', 'activatePermissionToUser');
    });

    Route::controller(MenuController::class)->prefix('menus')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{menu}', 'show');
        Route::put('/{menu}/activate/{activate}', 'activate');
        Route::put('/{menu}', 'update');
        Route::post('/check-menu', 'checkMenu');
    });
});

<?php

// User Management
use App\Http\Controllers\Admin\UserController;

Route::middleware(['auth:sanctum', 'user.change_password', 'check.permission'])->prefix('admin')->group(function () {
    Route::controller(UserController::class)->prefix('users')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{user}', 'show');
        Route::put('/{user}', 'update');
        Route::patch('/{user}/activate', 'changeStatus');
    });
});

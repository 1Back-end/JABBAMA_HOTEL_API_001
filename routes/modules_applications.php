<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('module_applications', \App\Http\Controllers\ModuleApplicationsController::class);
Route::get('/modules/{uuid}/permissions', [\App\Http\Controllers\ModuleApplicationsController::class, 'get_permissions_by_module']);
Route::patch('module_applications/{uuid}/is_active', [\App\Http\Controllers\ModuleApplicationsController::class, 'toggleActive']);

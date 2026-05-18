<?php
use Illuminate\Support\Facades\Route;
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

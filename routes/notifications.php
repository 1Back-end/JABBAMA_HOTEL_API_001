<?php

use Illuminate\Support\Facades\Route;
Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
Route::get('/notifications/unread', [\App\Http\Controllers\NotificationController::class, 'unread']);
Route::post('/notifications/{uuid}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
Route::post('/notifications/read_all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy']);


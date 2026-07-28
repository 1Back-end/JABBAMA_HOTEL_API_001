<?php

use Illuminate\Support\Facades\Route;
Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
Route::get('/notifications/decisional', [\App\Http\Controllers\NotificationController::class, 'decisionalIndex']);
Route::patch('/notifications/decisional/{uuid}/read', [\App\Http\Controllers\NotificationController::class, 'markAsReadNotificationDecisional']);
Route::post('/notifications/decisional/mark_all_as_read', [\App\Http\Controllers\NotificationController::class, 'markAllAsReadNotificationDecisional']);
Route::post('/notifications/{uuid}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
Route::post('/notifications/read_all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
Route::get('/notifications/stream', [\App\Http\Controllers\NotificationController::class, 'stream']);

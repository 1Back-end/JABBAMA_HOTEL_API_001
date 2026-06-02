<?php

use Illuminate\Support\Facades\Route;
Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
Route::get('/notifications/decisional', [\App\Http\Controllers\NotificationController::class, 'decisionalIndex']);
Route::patch('/notifications/decisional/{uuid}/read', [\App\Http\Controllers\NotificationController::class, 'markAsReadNotificationDecisional']);
Route::post('/notifications/decisional/mark_all_as_read', [\App\Http\Controllers\NotificationController::class, 'markAllAsReadNotificationDecisional']);
Route::get('/notifications/unread_count', [\App\Http\Controllers\NotificationController::class, 'unreadCount']);
Route::post('/notifications/{uuid}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
Route::post('/notifications/read_all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy']);


<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
/**
 * @permission_category Gestion des notifications du restaurant
 * @permission_module Gestion du restaurant
 */
class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission NotificationController::index
     * @permission_desc Afficher la liste de toute les notifications
     */
    public function index()
    {
        $user = auth()->user();

        return response()->json([
            'status' => 'success',
            'data' => $user->notifications
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NotificationController::unread
     * @permission_desc Afficher les notifications non lues
     */
    public function unread()
    {
        $user = auth()->user();

        return response()->json([
            'status' => 'success',
            'data' => $user->unreadNotifications
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NotificationController::markAsRead
     * @permission_desc Marquer les notifications comme lues
     */
    public function markAsRead(string $id)
    {
        $user = auth()->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification introuvable'
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marquée comme lue'
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NotificationController::markAllAsRead
     * @permission_desc Marquer toutes notifications comme lues
     */
    public function markAllAsRead()
    {
        $user = auth()->user();

        $user->unreadNotifications->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Toutes les notifications sont marquées comme lues'
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NotificationController::destroy
     * @permission_desc Supprimer une notification
     */
    public function destroy(string $id)
    {
        $user = auth()->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification introuvable'
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification supprimée'
        ]);
    }
}

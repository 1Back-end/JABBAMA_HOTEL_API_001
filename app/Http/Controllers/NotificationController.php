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
     * @permission_desc Afficher l'icone des notifications
     */
    public function index()
    {
        $user = auth()->user();

        $query = \App\Models\OrderNotification::with(['creator','updater']);

        if ($user->can('view_all_notification')) {
            $notifications = $query;
        } else if ($user->can('view_all_notification_in_preparation')) {
            $notifications = $query->where('status', 'in_preparation');
        } else if ($user->can('view_all_notification_transferred')) {
            $notifications = $query->where('status', 'transferred');
        } else if ($user->can('view_all_notification_rejected')) {
            $notifications = $query->where('status', 'rejected');
        } else if ($user->can('view_all_notification_in_defective')) {
            $notifications = $query->where('status', 'defective');
        } else if ($user->can('view_all_notification_in_ready')) {
            $notifications = $query->where('status', 'ready');
        } else if ($user->can('view_all_notification_in_delivered')) {
            $notifications = $query->where('status', 'delivered');
        } else if ($user->can('view_all_notification_in_rejected_after_validation')) {
            $notifications = $query->where('status', 'rejected_after_validation');
        } else if ($user->can('view_all_notification_in_cancel_for_new_update')) {
            $notifications = $query->where('status', 'cancel_for_new_update');
        } else {
            $notifications = $query->where('status', 'transferred');
        }
        return response()->json([
            'status' => 'success',
            'data' => $notifications->latest()->get()
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

        $query = \App\Models\OrderNotification::with(['creator','updater'])->where('is_read', false);
        if ($user->can('view_all_notification')) {
            $notifications = $query;
        } else if ($user->can('view_all_notification_in_preparation')) {
            $notifications = $query->where('status', 'in_preparation');
        } else if ($user->can('view_all_notification_transferred')) {
            $notifications = $query->where('status', 'transferred');
        } else if ($user->can('view_all_notification_rejected')) {
            $notifications = $query->where('status', 'rejected');
        } else if ($user->can('view_all_notification_in_defective')) {
            $notifications = $query->where('status', 'defective');
        } else if ($user->can('view_all_notification_in_ready')) {
            $notifications = $query->where('status', 'ready');
        } else if ($user->can('view_all_notification_in_delivered')) {
            $notifications = $query->where('status', 'delivered');
        } else if ($user->can('view_all_notification_in_rejected_after_validation')) {
            $notifications = $query->where('status', 'rejected_after_validation');
        } else if ($user->can('view_all_notification_in_cancel_for_new_update')) {
            $notifications = $query->where('status', 'cancel_for_new_update');
        } else {
            $notifications = $query->where('status', 'transferred');
        }
        return response()->json([
            'status' => 'success',
            'data' => $notifications->latest()->get()
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

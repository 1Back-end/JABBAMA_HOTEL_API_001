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

        $query = \App\Models\OrderNotification::with([
            'creator',
            'updater',
            'order'
        ]);

        /*
        |--------------------------------------------------------------------------
        | ACCÈS TOTAL
        |--------------------------------------------------------------------------
        */
        if ($user->can('view_all_notification')) {
            return response()->json([
                'status' => 'success',
                'data' => $query->latest()->get()
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | STATUTS
        |--------------------------------------------------------------------------
        */
        $statuses = [];

        if ($user->can('view_all_notification_in_preparation')) $statuses[] = 'in_preparation';
        if ($user->can('view_all_notification_transferred')) $statuses[] = 'transferred';
        if ($user->can('view_all_notification_rejected')) $statuses[] = 'rejected';
        if ($user->can('view_all_notification_in_defective')) $statuses[] = 'defective';
        if ($user->can('view_all_notification_in_ready')) $statuses[] = 'ready';
        if ($user->can('view_all_notification_in_delivered')) $statuses[] = 'delivered';
        if ($user->can('view_all_notification_in_rejected_after_validation')) $statuses[] = 'rejected_after_validation';
        if ($user->can('view_all_notification_in_cancel_for_new_update')) $statuses[] = 'cancel_for_new_update';

        if (empty($statuses)) {
            $statuses[] = 'transferred';
        }

        $query->whereIn('status', $statuses);

        /*
        |--------------------------------------------------------------------------
        | FILTRE STRICT CUISINE / BAR (IMPORTANT)
        |--------------------------------------------------------------------------
        */
        $query->where(function ($q) use ($user) {

            /*
            |--------------------------------------------------------------------------
            | CUISINE
            |--------------------------------------------------------------------------
            */
            if ($user->can('view_kitchen_notifications')) {

                $q->orWhere(function ($sub) {

                    $sub->where('target', 'kitchen')
                        ->whereHas('order', function ($order) {
                            $order->whereHas('items');
                        });
                });
            }

            /*
            |--------------------------------------------------------------------------
            | BAR
            |--------------------------------------------------------------------------
            */
            if ($user->can('view_bar_notifications')) {

                $q->orWhere(function ($sub) {
                    $sub->where('target', 'bar')
                        ->whereHas('order', function ($order) {
                            $order->whereHas('drinks');
                        });
                });
            }

        });

        return response()->json([
            'status' => 'success',
            'data' => $query->latest()->get()
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

        $query = \App\Models\OrderNotification::with(['creator', 'updater'])
            ->where('is_read', false);

        // 🔥 utiliser plusieurs if et non else if
        // pour permettre à un utilisateur ayant plusieurs permissions
        // de voir plusieurs statuts

        if (!$user->can('view_all_notification')) {

            $statuses = [];

            if ($user->can('view_all_notification_in_preparation')) {
                $statuses[] = 'in_preparation';
            }

            if ($user->can('view_all_notification_transferred')) {
                $statuses[] = 'transferred';
            }

            if ($user->can('view_all_notification_rejected')) {
                $statuses[] = 'rejected';
            }

            if ($user->can('view_all_notification_in_defective')) {
                $statuses[] = 'defective';
            }

            if ($user->can('view_all_notification_in_ready')) {
                $statuses[] = 'ready';
            }

            if ($user->can('view_all_notification_in_delivered')) {
                $statuses[] = 'delivered';
            }

            if ($user->can('view_all_notification_in_rejected_after_validation')) {
                $statuses[] = 'rejected_after_validation';
            }

            if ($user->can('view_all_notification_in_cancel_for_new_update')) {
                $statuses[] = 'cancel_for_new_update';
            }

            // 🔥 fallback
            if (empty($statuses)) {
                $statuses[] = 'transferred';
            }

            $query->whereIn('status', $statuses);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->latest()->get()
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission NotificationController::markAsRead
     * @permission_desc Marquer les notifications comme lues
     */
    public function markAsRead(string $uuid)
    {
        $auth = auth()->user();

        $notification = \App\Models\OrderNotification::where('uuid', $uuid)->first();

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification introuvable'
            ], 404);
        }
        $notification->update([
            'updated_by' => $auth->id,
            'is_read' => true,
            'read_at' => now()
        ]);
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

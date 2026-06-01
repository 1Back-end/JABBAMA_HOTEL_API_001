<?php

namespace App\Http\Controllers;

use App\Models\OrderNotification;
use App\Models\UserOrderNotification;
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

        $query = OrderNotification::with(['creator', 'updater', 'order']);

        $statusPermissions = [
            'in_preparation' => ['view_all_notification_in_preparation', 'view_all_notification_in_preparation_by_admin',],
            'transferred' => ['view_all_notification_transferred', 'view_all_notification_transferred_by_admin',],
            'rejected' => ['view_all_notification_rejected', 'view_all_notification_rejected_by_admin',],
            'defective' => ['view_all_notification_in_defective', 'view_all_notification_in_defective_by_admin',],
            'ready' => ['view_all_notification_in_ready', 'view_all_notification_in_ready_by_admin',],
            'delivered' => ['view_all_notification_in_delivered', 'view_all_notification_in_delivered_by_admin',],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation', 'view_all_notification_in_rejected_after_validation_by_admin',],
            'cancel_for_new_update' => ['view_all_notification_in_cancel_for_new_update', 'view_all_notification_in_cancel_for_new_update_by_admin',],
            'partial_completed' => ['view_all_notification_in_partial_completed', 'view_all_notification_in_partial_completed_by_admin',],
            'partial_delivered' => ['view_all_notification_partial_delivered', 'view_all_notification_partial_delivered_by_admin',],
        ];
        $statuses = collect($statusPermissions)->filter(fn($perms) => collect($perms)->contains(fn($p) => $user->can($p)))->keys()
            ->toArray();

        if (empty($statuses)) {
            return response()->json(['data' => []]);
        }

        $query->whereIn('status', $statuses);

        $query->where(function ($q) use ($user) {

            if ($user->can('view_kitchen_notifications')) {
                $q->orWhere(function ($sub) {
                    $sub->whereIn('target', ['kitchen', 'all']);
                });
            }

            if ($user->can('view_bar_notifications')) {
                $q->orWhere(function ($sub) {
                    $sub->whereIn('target', ['bar', 'all']);
                });
            }
        });

        return response()->json([
            'data' => $query->latest()->limit(50)->get()
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
    }


    public function markAllAsRead()
    {
        $user = auth()->user();
        if (!$user->can('mark_all_notifications_as_read')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Permission refusée'
            ], 403);
        }

        $statusPermissions = [
            'in_preparation' => ['view_all_notification_in_preparation', 'view_all_notification_in_preparation_by_admin'],
            'transferred' => ['view_all_notification_transferred', 'view_all_notification_transferred_by_admin'],
            'rejected' => ['view_all_notification_rejected', 'view_all_notification_rejected_by_admin'],
            'defective' => ['view_all_notification_in_defective', 'view_all_notification_in_defective_by_admin'],
            'ready' => ['view_all_notification_in_ready', 'view_all_notification_in_ready_by_admin'],
            'delivered' => ['view_all_notification_in_delivered', 'view_all_notification_in_delivered_by_admin'],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation', 'view_all_notification_in_rejected_after_validation_by_admin'],
            'cancel_for_new_update' => ['view_all_notification_in_cancel_for_new_update', 'view_all_notification_in_cancel_for_new_update_by_admin'],
            'partial_completed' => ['view_all_notification_in_partial_completed', 'view_all_notification_in_partial_completed_by_admin'],
            'partial_delivered' => ['view_all_notification_partial_delivered', 'view_all_notification_partial_delivered_by_admin'],
        ];

        $statuses = collect($statusPermissions)->filter(fn ($perms) => collect($perms)->contains(fn ($p) => $user->can($p)))
            ->keys()->values()->toArray();

        if (empty($statuses)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Aucune notification à marquer'
            ]);
        }

        OrderNotification::whereIn('status', $statuses)->whereNull('read_at')
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_by' => $user->id
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Toutes les notifications ont été marquées comme lues'
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

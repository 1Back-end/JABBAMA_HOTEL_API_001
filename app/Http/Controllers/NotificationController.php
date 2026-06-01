<?php

namespace App\Http\Controllers;

use App\Models\NotificationOrderRestaurantForDecisional;
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

        $query = OrderNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'order:uuid,code',
            'reads' => function ($q) use ($user) {
                $q->where('user_id', $user->id);
            }
        ]);

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
            'partial_delivered' => ['view_all_notification_in_partial_delivered', 'view_all_notification_in_partial_delivered_by_admin',],
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

        $notifications = $query->latest()->limit(50)->get();

        $notifications->each(function ($notif) {
            $notif->is_read = $notif->reads->isNotEmpty();
        });
        return response()->json([
            'data' => $notifications
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NotificationController::markAsRead
     * @permission_desc Marquer les notifications comme lues
     */
    public function markAsRead(string $uuid)
    {
        $user = auth()->user();

        $notification = \App\Models\OrderNotification::where('uuid', $uuid)->first();

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification introuvable'
            ], 404);
        }

        \App\Models\NotificationRead::updateOrCreate(
            [
                'notification_uuid' => $notification->uuid,
                'user_id' => $user->id,
            ],
            [
                'read_at' => now(),
                'updated_by' => $user->id,
                'created_by' => $user->id,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marquée comme lue',
            'data' => [
                'uuid' => $notification->uuid,
                'is_read' => true
            ]
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
            'in_preparation' => ['view_all_notification_in_preparation', 'view_all_notification_in_preparation_by_admin',],
            'transferred' => ['view_all_notification_transferred', 'view_all_notification_transferred_by_admin',],
            'rejected' => ['view_all_notification_rejected', 'view_all_notification_rejected_by_admin',],
            'defective' => ['view_all_notification_in_defective', 'view_all_notification_in_defective_by_admin',],
            'ready' => ['view_all_notification_in_ready', 'view_all_notification_in_ready_by_admin',],
            'delivered' => ['view_all_notification_in_delivered', 'view_all_notification_in_delivered_by_admin',],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation', 'view_all_notification_in_rejected_after_validation_by_admin',],
            'cancel_for_new_update' => ['view_all_notification_in_cancel_for_new_update', 'view_all_notification_in_cancel_for_new_update_by_admin',],
            'partial_completed' => ['view_all_notification_in_partial_completed', 'view_all_notification_in_partial_completed_by_admin',],
            'partial_delivered' => ['view_all_notification_in_partial_delivered', 'view_all_notification_in_partial_delivered_by_admin',],
        ];

        $statuses = collect($statusPermissions)
            ->filter(fn ($perms) => collect($perms)->contains(fn ($p) => $user->can($p)))->keys()
            ->toArray();

        if (empty($statuses)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Aucune notification à marquer'
            ]);
        }

        $notifications = OrderNotification::whereIn('status', $statuses)
            ->get(['uuid']);

        $now = now();

        $data = [];

        foreach ($notifications as $notif) {
            $data[] = [
                'notification_uuid' => $notif->uuid,
                'user_id' => $user->id,
                'read_at' => $now,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        \App\Models\NotificationRead::upsert(
            $data,
            ['notification_uuid', 'user_id'],
            ['read_at', 'updated_by', 'updated_at']
        );

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

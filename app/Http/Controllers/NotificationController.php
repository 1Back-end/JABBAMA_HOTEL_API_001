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

        $query = \App\Models\OrderNotification::with([
            'creator',
            'updater',
            'order',
        ]);


        $statuses = [];

        if ($user->can('view_all_notification_in_preparation')) $statuses[] = 'in_preparation';
        if ($user->can('view_all_notification_transferred')) $statuses[] = 'transferred';
        if ($user->can('view_all_notification_rejected')) $statuses[] = 'rejected';
        if ($user->can('view_all_notification_in_defective')) $statuses[] = 'defective';
        if ($user->can('view_all_notification_in_ready')) $statuses[] = 'ready';
        if ($user->can('view_all_notification_in_delivered')) $statuses[] = 'delivered';
        if ($user->can('view_all_notification_in_rejected_after_validation')) $statuses[] = 'rejected_after_validation';
        if ($user->can('view_all_notification_in_cancel_for_new_update')) $statuses[] = 'cancel_for_new_update';
        if ($user->can('view_all_notification_in_partial_completed')) $statuses[] = 'partial_completed';
        if ($user->can('view_all_notification_in_partial_delivered')) $statuses[] = 'partial_delivered';

        if (empty($statuses)) {

            return response()->json([
                'status' => 'success',
                'data' => []
            ]);
        }

        $query->whereIn('status', $statuses);

        $query->where(function ($q) use ($user) {

            if ($user->can('view_kitchen_notifications')) {
                $q->orWhere(function ($sub) {
                    $sub->whereIn('target', ['all', 'kitchen'])
                        ->orWhere(function ($q2) {
                            $q2->where('target', 'kitchen')
                                ->whereHas('order', function ($order) {
                                    $order->whereHas('items');
                                });
                        });
                });
            }

            if ($user->can('view_bar_notifications')) {
                $q->orWhere(function ($sub) {
                    $sub->whereIn('target', ['all', 'bar'])
                        ->orWhere(function ($q2) {
                            $q2->where('target', 'bar')
                                ->whereHas('order', function ($order) {
                                    $order->whereHas('drinks');
                                });
                        });
                });
            }

        });

        return response()->json([
            'status' => 'success',
            'data' => $query->latest()->get()
        ]);
    }


    public function decisionalIndex()
    {
        if (session_id()) {
            session_write_close();
        }

        $user = auth()->user();

        $query = NotificationOrderRestaurantForDecisional::with([
            'user:id,nom_utilisateur',
            'updatedBy:id,nom_utilisateur',
            'orderMenuRestaurant:uuid,code'
        ]);

        $statusPermissions = [
            'in_preparation' => ['view_all_notification_in_preparation_by_admin'],
            'transferred' => ['view_all_notification_transferred_by_admin'],
            'rejected' => ['view_all_notification_rejected_by_admin'],
            'defective' => ['view_all_notification_in_defective_by_admin'],
            'ready' => ['view_all_notification_in_ready_by_admin'],
            'delivered' => ['view_all_notification_in_delivered_by_admin'],
            'cancel_for_new_update' => ['view_all_notification_in_cancel_for_new_update_by_admin'],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation_by_admin'],
            'partial_delivered' => ['view_all_notification_in_partial_delivered_by_admin'],
            'partial_completed' => ['view_all_notification_in_partial_completed_by_admin'],
        ];

        $statuses = collect($statusPermissions)->filter(fn ($perms) => collect($perms)->contains(fn ($p) => $user->can($p)))->keys()->toArray();
        if (empty($statuses)) {
            return response()->json([
                'data' => [],
                'total' => 0,
                'unread' => 0
            ]);
        }

        $query->whereIn('status', $statuses);
        $total = (clone $query)->count();
        $unread = (clone $query)->whereNull('read_at')->count();
        $data = $query->latest()->limit(50)->get();

        return response()->json([
            'data' => $data,
            'total' => $total,
            'unread' => $unread
        ]);
    }


    public function markAllAsReadNotificationDecisional()
    {
        $user = auth()->user();

        $statusPermissions = [
            'in_preparation'            => ['view_all_notification_in_preparation_by_admin'],
            'transferred'               => ['view_all_notification_transferred_by_admin'],
            'rejected'                  => ['view_all_notification_rejected_by_admin'],
            'defective'                 => ['view_all_notification_in_defective_by_admin'],
            'ready'                     => ['view_all_notification_in_ready_by_admin'],
            'delivered'                 => ['view_all_notification_in_delivered_by_admin'],
            'cancel_for_new_update'     => ['view_all_notification_in_cancel_for_new_update_by_admin'],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation_by_admin'],
            'partial_delivered'         => ['view_all_notification_in_partial_delivered_by_admin'],
            'partial_completed'         => ['view_all_notification_in_partial_completed_by_admin'],
        ];

        $statuses = collect($statusPermissions)
            ->filter(fn ($perms) => collect($perms)->contains(fn ($p) => $user->can($p)))
            ->keys()
            ->toArray();

        if (empty($statuses)) {
            return response()->json([
                'message' => 'Aucune notification à marquer comme lue.',
                'updated_count' => 0
            ]);
        }

        $query = NotificationOrderRestaurantForDecisional::query()
            ->whereNull('read_at')
            ->whereIn('status', $statuses);

        if (!$user->can('mark_all_decisional_notifications_as_read')) {
            $query->where('user_id', $user->id);
        }

        $updatedCount = $query->update([
            'is_read' => true,
            'read_at' => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Notifications marquées comme lues avec succès.',
            'updated_count' => $updatedCount
        ]);
    }

    public function markAsReadNotificationDecisional(string $uuid)
    {
        $user = auth()->user();

        $statusPermissions = [
            'in_preparation'            => ['view_all_notification_in_preparation_by_admin'],
            'transferred'               => ['view_all_notification_transferred_by_admin'],
            'rejected'                  => ['view_all_notification_rejected_by_admin'],
            'defective'                 => ['view_all_notification_in_defective_by_admin'],
            'ready'                     => ['view_all_notification_in_ready_by_admin'],
            'delivered'                 => ['view_all_notification_in_delivered_by_admin'],
            'cancel_for_new_update'     => ['view_all_notification_in_cancel_for_new_update_by_admin'],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation_by_admin'],
            'partial_delivered'         => ['view_all_notification_in_partial_delivered_by_admin'],
            'partial_completed'         => ['view_all_notification_in_partial_completed_by_admin'],
        ];

        $statuses = collect($statusPermissions)
            ->filter(fn ($perms) => collect($perms)->contains(
                fn ($p) => $user->can($p)
            ))
            ->keys()
            ->toArray();

        $query = NotificationOrderRestaurantForDecisional::query()
            ->where('uuid', $uuid)
            ->whereIn('status', $statuses);

        if (!$user->can('mark_all_decisional_notifications_as_read')) {
            $query->where('user_id', $user->id);
        }

        $notification = $query->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification introuvable.'
            ], 404);
        }

        $notification->update([
            'is_read'   => true,
            'read_at'   => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Notification marquée comme lue.',
            'data' => $notification
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission NotificationController::markAsRead
     * @permission_desc Marquer comme lues les notifications des postes opérationnels
     */
    public function markAsRead(string $uuid)
    {
        $user = auth()->user();

        $statusPermissions = [
            'in_preparation' => ['view_all_notification_in_preparation'],
            'transferred' => ['view_all_notification_transferred'],
            'rejected' => ['view_all_notification_rejected'],
            'defective' => ['view_all_notification_in_defective'],
            'ready' => ['view_all_notification_in_ready'],
            'delivered' => ['view_all_notification_in_delivered'],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation'],
            'cancel_for_new_update' => ['view_all_notification_in_cancel_for_new_update'],
            'partial_completed' => ['view_all_notification_in_partial_completed'],
            'partial_delivered' => ['view_all_notification_in_partial_delivered'],
        ];


        $statuses = collect($statusPermissions)->filter(fn ($perms) => collect($perms)->contains(fn ($p) => $user->can($p)))
            ->keys()->toArray();

        $query = OrderNotification::query()
            ->where('uuid', $uuid)
            ->whereIn('status', $statuses);

        $notification = $query->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification introuvable.'
            ], 404);
        }

        $notification->update([
            'is_read'   => true,
            'read_at'   => now(),
            'updated_by' => $user->id,
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
            'in_preparation' => ['view_all_notification_in_preparation'],
            'transferred' => ['view_all_notification_transferred'],
            'rejected' => ['view_all_notification_rejected'],
            'defective' => ['view_all_notification_in_defective'],
            'ready' => ['view_all_notification_in_ready'],
            'delivered' => ['view_all_notification_in_delivered'],
            'rejected_after_validation' => ['view_all_notification_in_rejected_after_validation'],
            'cancel_for_new_update' => ['view_all_notification_in_cancel_for_new_update'],
            'partial_completed' => ['view_all_notification_in_partial_completed'],
            'partial_delivered' => ['view_all_notification_in_partial_delivered'],
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

}

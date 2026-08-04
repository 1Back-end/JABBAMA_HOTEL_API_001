<?php

namespace App\Http\Controllers;

use App\Models\NotificationOrderRestaurantForDecisional;
use App\Models\OrderNotification;
use Illuminate\Http\Request;
/**
 * @permission_category Gestion des notifications du restaurant
 * @permission_module Gestion du restaurant
 * @permission_module Gestion des stocks
 */
class NotificationController extends Controller
{

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
        $notifications = $query->latest()->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifications
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


    public function stream(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $notificationsQuery = \App\Models\OrderNotification::with(['creator:id,nom_utilisateur', 'updater', 'order:uuid,code']);

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

        $notifications = [];
        if (!empty($statuses)) {
            $notificationsQuery->whereIn('status', $statuses);

            $notificationsQuery->where(function ($q) use ($user) {
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

            $notifications = $notificationsQuery->latest()->limit(50)->get();
        }

        $decisionalQuery = \App\Models\NotificationOrderRestaurantForDecisional::with([
            'user:id,nom_utilisateur',
            'updatedBy:id,nom_utilisateur',
            'orderMenuRestaurant:uuid,code'
        ]);

        $decisionalPermissions = [
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

        $decisionalStatuses = collect($decisionalPermissions)
            ->filter(fn ($perms) => collect($perms)->contains(fn ($p) => $user->can($p)))
            ->keys()
            ->toArray();

        $decisionalData = [
            'data' => [],
            'total' => 0,
            'unread' => 0
        ];

        if (!empty($decisionalStatuses)) {
            $decisionalQuery->whereIn('status', $decisionalStatuses);

            $total = (clone $decisionalQuery)->count();
            $unread = (clone $decisionalQuery)->whereNull('read_at')->count();
            $data = $decisionalQuery->latest()->limit(50)->get();

            $decisionalData = [
                'data' => $data,
                'total' => $total,
                'unread' => $unread
            ];
        }

        $stockNotificationsQuery = \App\Models\PurchaseOrderNotification::with(['creator:id,nom_utilisateur', 'updater:id,nom_utilisateur', 'purchaseOrder:uuid,reference']);

        $stockStatuses = [];
        if ($user->can('view_stock_notification_draft')) $stockStatuses[] = 'draft';
        if ($user->can('view_stock_notification_open')) $stockStatuses[] = 'open';
        if ($user->can('view_stock_notification_validated')) $stockStatuses[] = 'validated';
        if ($user->can('view_stock_notification_in_discuss')) $stockStatuses[] = 'in_discuss';
        if ($user->can('view_stock_notification_rejected')) $stockStatuses[] = 'rejected';
        if ($user->can('view_stock_notification_cancel')) $stockStatuses[] = 'cancel';
        if ($user->can('view_stock_notification_partially_closed')) $stockStatuses[] = 'partially_closed';
        if ($user->can('view_stock_notification_closed')) $stockStatuses[] = 'closed';

        $stocks = [];
        if (!empty($stockStatuses)) {
            $stockNotificationsQuery->whereIn('status', $stockStatuses);
            $stocks = $stockNotificationsQuery->latest()->limit(50)->get();
        }

        $decisionalStockQuery = \App\Models\DecisionalNotification::with(['creator:id,nom_utilisateur', 'updater:id,nom_utilisateur', 'purchaseOrder:uuid,reference']);

        $decisionalStockStatuses = [];
        if ($user->can('view_decisional_stock_notification_draft')) $decisionalStockStatuses[] = 'draft';
        if ($user->can('view_decisional_stock_notification_open')) $decisionalStockStatuses[] = 'open';
        if ($user->can('view_decisional_stock_notification_validated')) $decisionalStockStatuses[] = 'validated';
        if ($user->can('view_decisional_stock_notification_in_discuss')) $decisionalStockStatuses[] = 'in_discuss';
        if ($user->can('view_decisional_stock_notification_rejected')) $decisionalStockStatuses[] = 'rejected';
        if ($user->can('view_decisional_stock_notification_cancel')) $decisionalStockStatuses[] = 'cancel';
        if ($user->can('view_decisional_stock_notification_partially_closed')) $decisionalStockStatuses[] = 'partially_closed';
        if ($user->can('view_decisional_stock_notification_closed')) $decisionalStockStatuses[] = 'closed';

        $decisionalStocks = [
            'data' => [],
            'total' => 0,
            'unread' => 0
        ];

        if (!empty($decisionalStockStatuses)) {
            $decisionalStockQuery->whereIn('status', $decisionalStockStatuses);

            $totalDecisionalStock = (clone $decisionalStockQuery)->count();
            $unreadDecisionalStock = (clone $decisionalStockQuery)->where('is_read', false)->count();
            $dataDecisionalStock = $decisionalStockQuery->latest()->limit(50)->get();

            $decisionalStocks = [
                'data' => $dataDecisionalStock,
                'total' => $totalDecisionalStock,
                'unread' => $unreadDecisionalStock
            ];
        }


        return response()->json([
            'notifications' => $notifications,
            'decisional' => $decisionalData,
            'stocks' => $stocks,
            'decisional_stocks' => $decisionalStocks
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
     * @permission_desc Marquer comme lues les notifications des postes opérationnels un à un
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


    public function markAsReadNotificationForPurchaseOrers(string $uuid)
    {
        $user = auth()->user();

        // 🔹 Vérifier si l'utilisateur a la permission globale de marquer comme lu
        if (!$user->can('mark_stock_notification_as_read')) {
            return response()->json([
                'message' => "Vous n'avez pas la permission de marquer cette notification comme lue."
            ], 403);
        }

        $stockPermissions = [
            'draft' => 'view_stock_notification_draft',
            'open' => 'view_stock_notification_open',
            'validated' => 'view_stock_notification_validated',
            'in_discuss' => 'view_stock_notification_in_discuss',
            'rejected' => 'view_stock_notification_rejected',
            'cancel' => 'view_stock_notification_cancel',
            'partially_closed' => 'view_stock_notification_partially_closed',
            'closed' => 'view_stock_notification_closed',
        ];

        // 🔹 Récupération des statuts autorisés pour l'utilisateur
        $statuses = collect($stockPermissions)
            ->filter(fn ($permission) => $user->can($permission))
            ->keys()
            ->toArray();

        $notification = \App\Models\PurchaseOrderNotification::query()
            ->where('uuid', $uuid)
            ->whereIn('status', $statuses)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification introuvable ou accès non autorisé.'
            ], 404);
        }

        $notification->update([
            'is_read'    => true,
            'read_at'    => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Notification de stock marquée comme lue avec succès.',
            'notification' => $notification
        ]);
    }


    public function markAllAsReadNotificationForPurchaseOrders()
    {
        $user = auth()->user();

        if (!$user->can('mark_all_stock_notifications_as_read')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Permission refusée'
            ], 403);
        }

        $stockPermissions = [
            'draft' => 'view_stock_notification_draft',
            'open' => 'view_stock_notification_open',
            'validated' => 'view_stock_notification_validated',
            'in_discuss' => 'view_stock_notification_in_discuss',
            'rejected' => 'view_stock_notification_rejected',
            'cancel' => 'view_stock_notification_cancel',
            'partially_closed' => 'view_stock_notification_partially_closed',
            'closed' => 'view_stock_notification_closed',
        ];

        $statuses = collect($stockPermissions)
            ->filter(fn ($permission) => $user->can($permission))
            ->keys()
            ->values()
            ->toArray();

        if (empty($statuses)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Aucune notification de stock à marquer'
            ]);
        }

        \App\Models\PurchaseOrderNotification::whereIn('status', $statuses)
            ->where('is_read', false)
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Toutes les notifications de stock ont été marquées comme lues'
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


    public function markAllAsReadDecisionalStockNotification()
    {
        $user = auth()->user();

        $stockStatuses = [];
        if ($user->can('view_decisional_stock_notification_draft')) $stockStatuses[] = 'draft';
        if ($user->can('view_decisional_stock_notification_open')) $stockStatuses[] = 'open';
        if ($user->can('view_decisional_stock_notification_validated')) $stockStatuses[] = 'validated';
        if ($user->can('view_decisional_stock_notification_in_discuss')) $stockStatuses[] = 'in_discuss';
        if ($user->can('view_decisional_stock_notification_rejected')) $stockStatuses[] = 'rejected';
        if ($user->can('view_decisional_stock_notification_cancel')) $stockStatuses[] = 'cancel';
        if ($user->can('view_decisional_stock_notification_partially_closed')) $stockStatuses[] = 'partially_closed';
        if ($user->can('view_decisional_stock_notification_closed')) $stockStatuses[] = 'closed';

        if (empty($stockStatuses)) {
            return response()->json([
                'message' => 'Aucune notification de stock décisionnelle à marquer comme lue.',
                'updated_count' => 0
            ]);
        }

        $query = \App\Models\DecisionalNotification::query()
            ->where('is_read', false)
            ->whereIn('status', $stockStatuses);

        if (!$user->can('mark_all_decisional_stock_notifications_as_read')) {
            $query->where('user_id', $user->id);
        }

        $updatedCount = $query->update([
            'is_read' => true,
            'updated_by' => $user->id,
            'read_at' => now()
        ]);

        return response()->json([
            'message' => 'Notifications de stocks décisionnelles marquées comme lues avec succès.',
            'updated_count' => $updatedCount
        ]);
    }

    public function markAsReadDecisionalStockNotification(string $uuid)
    {
        $user = auth()->user();

        $stockStatuses = [];
        if ($user->can('view_decisional_stock_notification_draft')) $stockStatuses[] = 'draft';
        if ($user->can('view_decisional_stock_notification_open')) $stockStatuses[] = 'open';
        if ($user->can('view_decisional_stock_notification_validated')) $stockStatuses[] = 'validated';
        if ($user->can('view_decisional_stock_notification_in_discuss')) $stockStatuses[] = 'in_discuss';
        if ($user->can('view_decisional_stock_notification_rejected')) $stockStatuses[] = 'rejected';
        if ($user->can('view_decisional_stock_notification_cancel')) $stockStatuses[] = 'cancel';
        if ($user->can('view_decisional_stock_notification_partially_closed')) $stockStatuses[] = 'partially_closed';
        if ($user->can('view_decisional_stock_notification_closed')) $stockStatuses[] = 'closed';

        $query = \App\Models\DecisionalNotification::query()
            ->where('uuid', $uuid)
            ->whereIn('status', $stockStatuses);

        if (!$user->can('mark_all_decisional_stock_notifications_as_read')) {
            $query->where('user_id', $user->id);
        }

        $notification = $query->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification de stock décisionnelle introuvable.'
            ], 404);
        }

        $notification->update([
            'is_read' => true,
            'updated_by' => $user->id,
            'read_at' => now()
        ]);

        return response()->json([
            'message' => 'Notification de stock décisionnelle marquée comme lue.',
            'data' => $notification
        ]);
    }

}

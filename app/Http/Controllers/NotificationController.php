<?php

namespace App\Http\Controllers;

use App\Models\DecisionalNotification;
use App\Models\NotificationOrderRestaurantForDecisional;
use App\Models\OrderNotification;
use App\Models\PassationDecisionnelle;
use App\Models\PassationNotification;
use App\Models\PurchaseOrderNotification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;


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


    private const STREAM_LIMIT = 50;
    private const ORDER_STATUS_PERMISSIONS = [
        'in_preparation'            => 'view_all_notification_in_preparation',
        'transferred'               => 'view_all_notification_transferred',
        'rejected'                  => 'view_all_notification_rejected',
        'defective'                 => 'view_all_notification_in_defective',
        'ready'                     => 'view_all_notification_in_ready',
        'delivered'                 => 'view_all_notification_in_delivered',
        'rejected_after_validation' => 'view_all_notification_in_rejected_after_validation',
        'cancel_for_new_update'     => 'view_all_notification_in_cancel_for_new_update',
        'partial_completed'         => 'view_all_notification_in_partial_completed',
        'partial_delivered'         => 'view_all_notification_in_partial_delivered',
    ];
    private const DECISIONAL_STATUS_PERMISSIONS = [
        'in_preparation'            => 'view_all_notification_in_preparation_by_admin',
        'transferred'               => 'view_all_notification_transferred_by_admin',
        'rejected'                  => 'view_all_notification_rejected_by_admin',
        'defective'                 => 'view_all_notification_in_defective_by_admin',
        'ready'                     => 'view_all_notification_in_ready_by_admin',
        'delivered'                 => 'view_all_notification_in_delivered_by_admin',
        'cancel_for_new_update'     => 'view_all_notification_in_cancel_for_new_update_by_admin',
        'rejected_after_validation' => 'view_all_notification_in_rejected_after_validation_by_admin',
        'partial_delivered'         => 'view_all_notification_in_partial_delivered_by_admin',
        'partial_completed'         => 'view_all_notification_in_partial_completed_by_admin',
    ];

    /** statut => permission (notifications de stock) */
    private const STOCK_STATUS_PERMISSIONS = [
        'draft'            => 'view_stock_notification_draft',
        'open'             => 'view_stock_notification_open',
        'validated'        => 'view_stock_notification_validated',
        'in_discuss'       => 'view_stock_notification_in_discuss',
        'rejected'         => 'view_stock_notification_rejected',
        'cancel'           => 'view_stock_notification_cancel',
        'partially_closed' => 'view_stock_notification_partially_closed',
        'closed'           => 'view_stock_notification_closed',
    ];
    private const DECISIONAL_STOCK_STATUS_PERMISSIONS = [
        'draft'            => 'view_decisional_stock_notification_draft',
        'open'             => 'view_decisional_stock_notification_open',
        'validated'        => 'view_decisional_stock_notification_validated',
        'in_discuss'       => 'view_decisional_stock_notification_in_discuss',
        'rejected'         => 'view_decisional_stock_notification_rejected',
        'cancel'           => 'view_decisional_stock_notification_cancel',
        'partially_closed' => 'view_decisional_stock_notification_partially_closed',
        'closed'           => 'view_decisional_stock_notification_closed',
    ];
    private const PASSATION_STATUS_PERMISSIONS = [
        'pending'    => 'view_passations_notification_pending',
        'cancel'     => 'view_passations_notification_cancel',
        'in_discuss' => 'view_passations_notification_in_discuss',
        'no_gap'     => 'view_passations_notification_no_gap',
        'with_gap'   => 'view_passations_notification_with_gap',
        'closed'     => 'view_passations_notification_closed',
        'rejected'   => 'view_passations_notification_rejected',
    ];
    private const DECISIONAL_PASSATION_STATUS_PERMISSIONS = [
        'pending'    => 'view_passations_notification_pending_by_admin',
        'cancel'     => 'view_passations_notification_cancel_by_admin',
        'in_discuss' => 'view_passations_notification_in_discuss_by_admin',
        'no_gap'     => 'view_passations_notification_no_gap_by_admin',
        'with_gap'   => 'view_passations_notification_with_gap_by_admin',
        'closed'     => 'view_passations_notification_closed_by_admin',
        'rejected'   => 'view_passations_notification_rejected_by_admin',
    ];


    public function stream(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $allowedStatuses = fn (array $map): array => array_keys(
            array_filter($map, fn (string $permission) => $user->can($permission))
        );

        return response()->json([
            'notifications'     => $this->streamOrderNotifications(
                $user,
                $allowedStatuses(self::ORDER_STATUS_PERMISSIONS)
            ),
            'decisional'        => $this->streamDecisionalNotifications(
                $allowedStatuses(self::DECISIONAL_STATUS_PERMISSIONS)
            ),
            'stocks'            => $this->streamStockNotifications(
                $allowedStatuses(self::STOCK_STATUS_PERMISSIONS)
            ),
            'decisional_stocks' => $this->streamDecisionalStockNotifications(
                $allowedStatuses(self::DECISIONAL_STOCK_STATUS_PERMISSIONS)
            ),
            'passations'          => $this->streamPassationNotifications(
                $allowedStatuses(self::PASSATION_STATUS_PERMISSIONS)
            ),
            'decisional_passations' => $this->streamDecisionalPassationNotifications(
                $allowedStatuses(self::DECISIONAL_PASSATION_STATUS_PERMISSIONS)
            ),
        ]);
    }

    private function streamOrderNotifications($user, array $statuses)
    {
        if (empty($statuses)) {
            return [];
        }

        $targets = [];
        if ($user->can('view_kitchen_notifications')) {
            $targets[] = 'kitchen';
        }
        if ($user->can('view_bar_notifications')) {
            $targets[] = 'bar';
        }

        if (empty($targets)) {
            return [];
        }
        $targets[] = 'all';

        return OrderNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'order:uuid,code',
        ])
            ->whereIn('status', $statuses)
            ->whereIn('target', $targets)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();
    }

    private function streamDecisionalNotifications(array $statuses): array
    {
        if (empty($statuses)) {
            return ['data' => []];
        }

        $data = NotificationOrderRestaurantForDecisional::with([
            'user:id,nom_utilisateur',
            'updatedBy:id,nom_utilisateur',
            'orderMenuRestaurant:uuid,code',
        ])
            ->whereIn('status', $statuses)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
    }

    private function streamStockNotifications(array $statuses)
    {
        if (empty($statuses)) {
            return [];
        }

        return PurchaseOrderNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'purchaseOrder:uuid,reference',
        ])
            ->whereIn('status', $statuses)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();
    }

    private function streamDecisionalStockNotifications(array $statuses): array
    {
        if (empty($statuses)) {
            return ['data' => []];
        }

        $data = DecisionalNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'purchaseOrder:uuid,reference',
        ])
            ->whereIn('status', $statuses)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
    }

    private function streamPassationNotifications(array $statuses)
    {
        if (empty($statuses)) {
            return ['data' => []];
        }

        $data = PassationNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'passation:uuid',
        ])
            ->whereIn('status', $statuses)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
    }

    private function streamDecisionalPassationNotifications(array $statuses)
    {
        if (empty($statuses)) {
            return ['data' => []];
        }

        $data = PassationDecisionnelle::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'passation:uuid',
        ])
            ->whereIn('status', $statuses)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
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

    public function markAsReadDecisionalPassationNotification(string $uuid)
    {
        $user = auth()->user();

        $decisionalPassationStatuses = [];
        if ($user->can('view_passations_notification_pending_by_admin')) $decisionalPassationStatuses[] = 'pending';
        if ($user->can('view_passations_notification_cancel_by_admin')) $decisionalPassationStatuses[] = 'cancel';
        if ($user->can('view_passations_notification_in_discuss_by_admin')) $decisionalPassationStatuses[] = 'in_discuss';
        if ($user->can('view_passations_notification_no_gap_by_admin')) $decisionalPassationStatuses[] = 'no_gap';
        if ($user->can('view_passations_notification_with_gap_by_admin')) $decisionalPassationStatuses[] = 'with_gap';
        if ($user->can('view_passations_notification_closed_by_admin')) $decisionalPassationStatuses[] = 'closed';
        if ($user->can('view_passations_notification_rejected_by_admin')) $decisionalPassationStatuses[] = 'rejected';

        $query = \App\Models\PassationDecisionnelle::query()
            ->where('uuid', $uuid)
            ->whereIn('status', $decisionalPassationStatuses);

        if (!$user->can('mark_all_as_read_decisional_passations_notification')) {
            $query->where('user_id', $user->id);
        }

        $notification = $query->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification décisionnelle de passation introuvable.'
            ], 404);
        }

        $notification->update([
            'is_read' => true,
            'updated_by' => $user->id,
            'read_at' => now()
        ]);

        return response()->json([
            'message' => 'Notification décisionnelle de passation marquée comme lue.',
            'data' => $notification
        ]);
    }

    public function markAsReadPassationNotification(string $uuid)
    {
        $user = auth()->user();

        if (!$user->can('mark_as_read_passations_notification')) {
            return response()->json([
                'message' => "Vous n'avez pas la permission de marquer cette notification de passation comme lue."
            ], 403);
        }

        $passationPermissions = [
            'pending'    => 'view_passations_notification_pending',
            'cancel'     => 'view_passations_notification_cancel',
            'in_discuss' => 'view_passations_notification_in_discuss',
            'no_gap'     => 'view_passations_notification_no_gap',
            'with_gap'   => 'view_passations_notification_with_gap',
            'closed'     => 'view_passations_notification_closed',
            'rejected'   => 'view_passations_notification_rejected',
        ];

        $statuses = collect($passationPermissions)
            ->filter(fn ($permission) => $user->can($permission))
            ->keys()
            ->toArray();

        $notification = \App\Models\PassationNotification::query()
            ->where('uuid', $uuid)
            ->whereIn('status', $statuses)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification de passation introuvable ou accès non autorisé.'
            ], 404);
        }

        $notification->update([
            'is_read'    => true,
            'read_at'    => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Notification de passation marquée comme lue avec succès.',
            'notification' => $notification
        ]);
    }
    public function markAllAsReadPassationNotifications()
    {
        $user = auth()->user();

        if (!$user->can('mark_all_as_read_passations_notification')) {
            return response()->json([
                'message' => "Vous n'avez pas la permission de marquer toutes les notifications de passations comme lues."
            ], 403);
        }

        $passationPermissions = [
            'pending'    => 'view_passations_notification_pending',
            'cancel'     => 'view_passations_notification_cancel',
            'in_discuss' => 'view_passations_notification_in_discuss',
            'no_gap'     => 'view_passations_notification_no_gap',
            'with_gap'   => 'view_passations_notification_with_gap',
            'closed'     => 'view_passations_notification_closed',
            'rejected'   => 'view_passations_notification_rejected',
        ];

        $statuses = collect($passationPermissions)
            ->filter(fn ($permission) => $user->can($permission))
            ->keys()
            ->toArray();

        if (empty($statuses)) {
            return response()->json([
                'message' => 'Aucune permission active pour mettre à jour ces notifications.'
            ], 403);
        }

        \App\Models\PassationNotification::query()
            ->whereIn('status', $statuses)
            ->where('is_read', false)
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'message' => 'Toutes les notifications de passations ont été marquées comme lues avec succès.'
        ]);
    }

    public function markAllPassationsAsRead()
    {
        $user = auth()->user();

        $passationStatuses = [];
        if ($user->can('view_passations_notification_pending')) $passationStatuses[] = 'pending';
        if ($user->can('view_passations_notification_cancel')) $passationStatuses[] = 'cancel';
        if ($user->can('view_passations_notification_in_discuss')) $passationStatuses[] = 'in_discuss';
        if ($user->can('view_passations_notification_no_gap')) $passationStatuses[] = 'no_gap';
        if ($user->can('view_passations_notification_with_gap')) $passationStatuses[] = 'with_gap';
        if ($user->can('view_passations_notification_closed')) $passationStatuses[] = 'closed';
        if ($user->can('view_passations_notification_rejected')) $passationStatuses[] = 'rejected';

        $query = \App\Models\PassationNotification::query()
            ->whereIn('status', $passationStatuses)
            ->where('is_read', false);

        if (!$user->can('mark_all_as_read_passations_notification')) {
            $query->where('user_id', $user->id);
        }

        $query->update([
            'is_read' => true,
            'read_at' => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Toutes les notifications de passations ont été marquées comme lues.'
        ]);
    }

    public function markAllDecisionalPassationsAsRead()
    {
        $user = auth()->user();

        $decisionalPassationStatuses = [];
        if ($user->can('view_passations_notification_pending_by_admin')) $decisionalPassationStatuses[] = 'pending';
        if ($user->can('view_passations_notification_cancel_by_admin')) $decisionalPassationStatuses[] = 'cancel';
        if ($user->can('view_passations_notification_in_discuss_by_admin')) $decisionalPassationStatuses[] = 'in_discuss';
        if ($user->can('view_passations_notification_no_gap_by_admin')) $decisionalPassationStatuses[] = 'no_gap';
        if ($user->can('view_passations_notification_with_gap_by_admin')) $decisionalPassationStatuses[] = 'with_gap';
        if ($user->can('view_passations_notification_closed_by_admin')) $decisionalPassationStatuses[] = 'closed';
        if ($user->can('view_passations_notification_rejected_by_admin')) $decisionalPassationStatuses[] = 'rejected';

        $query = \App\Models\PassationDecisionnelle::query()
            ->whereIn('status', $decisionalPassationStatuses)
            ->where('is_read', false);

        if (!$user->can('mark_all_as_read_decisional_passations_notification')) {
            $query->where('user_id', $user->id);
        }

        $query->update([
            'is_read' => true,
            'read_at' => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Toutes les notifications décisionnelles de passations ont été marquées comme lues.'
        ]);
    }

}

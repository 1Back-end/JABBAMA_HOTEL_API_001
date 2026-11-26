<?php

namespace App\Http\Controllers;

use App\Models\DecisionalNotification;
use App\Models\NotificationOrderRestaurantForDecisional;
use App\Models\OrderNotification;
use App\Models\PassationDecisionnelle;
use App\Models\PassationNotification;
use App\Models\PurchaseOrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;


class NotificationController extends Controller
{


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


    private const STREAM_LIMIT = 100;
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

    private const STOCK_ADJUSTMENT_STATUS_PERMISSIONS = [
        'pending'   => 'view_stock_adjustments_notification_pending',
        'validated' => 'view_stock_adjustments_notification_validated',
        'cancelled' => 'view_stock_adjustments_notification_cancelled',
    ];
    private const STOCK_ADJUSTMENT_ADMIN_STATUS_PERMISSIONS = [
        'pending'   => 'view_admin_stock_adjustments_notification_pending',
        'validated' => 'view_admin_stock_adjustments_notification_validated',
        'cancelled' => 'view_admin_stock_adjustments_notification_cancelled',
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
            'notifications'         => $this->streamOrderNotifications(
                $user,
                $allowedStatuses(self::ORDER_STATUS_PERMISSIONS)
            ),
            'decisional'            => $this->streamDecisionalNotifications(
                $allowedStatuses(self::DECISIONAL_STATUS_PERMISSIONS)
            ),
            'stock_adjustments' => $this->streamStockAdjustmentNotifications(
                $user,
                $allowedStatuses(self::STOCK_ADJUSTMENT_STATUS_PERMISSIONS)
            ),
            'decisional_stock_adjustments' => $this->streamDecisionalStockAdjustmentNotifications(
                $allowedStatuses(self::STOCK_ADJUSTMENT_ADMIN_STATUS_PERMISSIONS)
            ),
            'stocks' => $this->streamStockNotifications(
                $user,
                $allowedStatuses(
                    self::STOCK_STATUS_PERMISSIONS)
            ),
            'decisional_stocks' => $this->streamDecisionalStockNotifications(
                $allowedStatuses(
                    self::DECISIONAL_STOCK_STATUS_PERMISSIONS)
            ),
            'passations'            => $this->streamPassationNotifications(
                $user,
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
            ->where('created_by', '!=', $user->id)
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
    private function streamStockNotifications($user, array $statuses)
    {
        if (empty($statuses) || !$user) {
            return ['data' => []];
        }

        $assignedWarehouseUuids = $user->warehouses()
            ->whereNull('warehouse_managers.deleted_at')
            ->pluck('warehouses.uuid')
            ->values();

        $query = PurchaseOrderNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'purchaseOrder:uuid,reference',
            'stockAdjustment:uuid,reference,action',
            'warehouse',
        ])
            ->whereIn('status', $statuses)
            ->whereIn('warehouse_uuid', $assignedWarehouseUuids)
            ->where('created_by', '!=', $user->id);

        $data = $query
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
    }
    private function streamStockAdjustmentNotifications($user, array $statuses)
    {
        if (empty($statuses) || !$user) {
            return ['data' => []];
        }

        $assignedWarehouseUuids = $user->warehouses()
            ->whereNull('warehouse_managers.deleted_at')
            ->pluck('warehouses.uuid')
            ->values();

        $query = PurchaseOrderNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'purchaseOrder:uuid,reference',
            'stockAdjustment:uuid,reference,action',
            'warehouse',
        ])
            ->where('is_stock_adjustment', true)
            ->whereIn('status', $statuses)
            ->whereIn('warehouse_uuid', $assignedWarehouseUuids)
            ->where('created_by', '!=', $user->id);

        $data = $query
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
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
    private function streamDecisionalStockAdjustmentNotifications(array $statuses): array
    {
        if (empty($statuses)) {
            return ['data' => []];
        }

        $data = DecisionalNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'purchaseOrder:uuid,reference',
            'stockAdjustment:uuid,reference,action',
        ])
            ->where(function ($q) {
                $q->where('is_stock_adjustment', false)
                    ->orWhereNull('is_stock_adjustment');
            })
            ->whereIn('status', $statuses)
            ->latest()
            ->limit(self::STREAM_LIMIT)
            ->get();

        return ['data' => $data];
    }
    private function streamPassationNotifications($user, array $statuses)
    {

        if (empty($statuses) || !$user) {
            return ['data' => []];
        }

        $assignedWarehouseUuids = $user->warehouses()
            ->whereNull('warehouse_managers.deleted_at')
            ->pluck('warehouses.uuid')
            ->values();

        $data = PurchaseOrderNotification::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'warehouse',
        ])
            ->whereIn('status', $statuses)
            ->whereIn('warehouse_uuid', $assignedWarehouseUuids)
            ->where('created_by', '!=', $user->id)
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

        $data = DecisionalNotification::with([
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
    private function authenticatedUser()
    {
        return auth()->user();
    }

    private function assignedWarehouseUuids($user)
    {
        if (!$user || !method_exists($user, 'warehouses')) {
            return collect();
        }

        return $user->warehouses()
            ->whereNull('warehouse_managers.deleted_at')
            ->pluck('warehouses.uuid');
    }

// --- Notifications standards / commandes / stocks (PurchaseOrderNotification) ---

    public function markAsRead(string $uuid)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $warehouseUuids = $this->assignedWarehouseUuids($user);

        $notification = PurchaseOrderNotification::query()
            ->where('uuid', $uuid)
            ->when($warehouseUuids->isNotEmpty(), function ($q) use ($warehouseUuids) {
                $q->whereIn('warehouse_uuid', $warehouseUuids);
            })
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notification introuvable.'], 404);
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message'      => 'Notification marquée comme lue.',
            'notification' => $notification->fresh(),
        ]);
    }

    public function markAllAsRead()
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $warehouseUuids = $this->assignedWarehouseUuids($user);

        $updatedCount = PurchaseOrderNotification::query()
            ->where('is_read', false)
            ->when($warehouseUuids->isNotEmpty(), function ($q) use ($warehouseUuids) {
                $q->whereIn('warehouse_uuid', $warehouseUuids);
            })
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'status'        => 'success',
            'message'       => 'Toutes les notifications ont été marquées comme lues',
            'updated_count' => $updatedCount,
        ]);
    }

    public function markAsReadNotificationForPurchaseOrers(string $uuid)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $warehouseUuids = $this->assignedWarehouseUuids($user);

        $notification = PurchaseOrderNotification::query()
            ->where('uuid', $uuid)
            ->when($warehouseUuids->isNotEmpty(), function ($q) use ($warehouseUuids) {
                $q->whereIn('warehouse_uuid', $warehouseUuids);
            })
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification introuvable ou accès non autorisé.',
            ], 404);
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message'      => 'Notification de stock marquée comme lue avec succès.',
            'notification' => $notification->fresh(),
        ]);
    }

    public function markAllAsReadNotificationForPurchaseOrders()
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $warehouseUuids = $this->assignedWarehouseUuids($user);

        $updatedCount = PurchaseOrderNotification::query()
            ->where('is_read', false)
            ->when($warehouseUuids->isNotEmpty(), function ($q) use ($warehouseUuids) {
                $q->whereIn('warehouse_uuid', $warehouseUuids);
            })
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'status'        => 'success',
            'message'       => 'Toutes les notifications de stock ont été marquées comme lues',
            'updated_count' => $updatedCount,
        ]);
    }

    public function markAsReadPassationNotification(string $uuid)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $warehouseUuids = $this->assignedWarehouseUuids($user);

        $notification = PurchaseOrderNotification::query()
            ->where('uuid', $uuid)
            ->when($warehouseUuids->isNotEmpty(), function ($q) use ($warehouseUuids) {
                $q->whereIn('warehouse_uuid', $warehouseUuids);
            })
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification de passation introuvable ou accès non autorisé.',
            ], 404);
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message'      => 'Notification de passation marquée comme lue avec succès.',
            'notification' => $notification->fresh(),
        ]);
    }

    public function markAllAsReadPassationNotifications()
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $warehouseUuids = $this->assignedWarehouseUuids($user);

        $updatedCount = PurchaseOrderNotification::query()
            ->where('is_read', false)
            ->when($warehouseUuids->isNotEmpty(), function ($q) use ($warehouseUuids) {
                $q->whereIn('warehouse_uuid', $warehouseUuids);
            })
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'message'       => 'Toutes les notifications de passations ont été marquées comme lues avec succès.',
            'updated_count' => $updatedCount,
        ]);
    }

    public function markAllPassationsAsRead()
    {
        return $this->markAllAsReadPassationNotifications();
    }

// --- Notifications décisionnelles (DecisionalNotification) ---

    public function markAsReadNotificationDecisional(string $uuid)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $notification = DecisionalNotification::query()
            ->where('uuid', $uuid)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notification introuvable.'], 404);
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message' => 'Notification marquée comme lue.',
            'data'    => $notification->fresh(),
        ]);
    }

    public function markAllAsReadNotificationDecisional()
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $updatedCount = DecisionalNotification::query()
            ->where('is_read', false)
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'message'       => 'Notifications marquées comme lues avec succès.',
            'updated_count' => $updatedCount,
        ]);
    }

    public function markAsReadDecisionalStockNotification(string $uuid)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $notification = DecisionalNotification::query()
            ->where('uuid', $uuid)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification de stock décisionnelle introuvable.',
            ], 404);
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message' => 'Notification de stock décisionnelle marquée comme lue.',
            'data'    => $notification->fresh(),
        ]);
    }

    public function markAllAsReadDecisionalStockNotification()
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $updatedCount = DecisionalNotification::query()
            ->where('is_read', false)
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'message'       => 'Notifications de stocks décisionnelles marquées comme lues avec succès.',
            'updated_count' => $updatedCount,
        ]);
    }

    public function markAsReadDecisionalPassationNotification(string $uuid)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $notification = DecisionalNotification::query()
            ->where('uuid', $uuid)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification décisionnelle de passation introuvable.',
            ], 404);
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message' => 'Notification décisionnelle de passation marquée comme lue.',
            'data'    => $notification->fresh(),
        ]);
    }

    public function markAllDecisionalPassationsAsRead()
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $updatedCount = DecisionalNotification::query()
            ->where('is_read', false)
            ->update([
                'is_read'    => true,
                'read_at'    => now(),
                'updated_by' => $user->id,
            ]);

        return response()->json([
            'message'       => 'Toutes les notifications décisionnelles de passations ont été marquées comme lues.',
            'updated_count' => $updatedCount,
        ]);
    }

}

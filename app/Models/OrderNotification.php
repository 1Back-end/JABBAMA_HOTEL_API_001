<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderNotification extends Model
{
    use HasFactory;

    protected $table = 'order_notifications';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'order_menu_restaurant_item_uuid',
        'status',
        'message',
        'created_by',
        'updated_by',
        'target',
        'kitchen_user_id',
        'bar_user_id',
        'is_read',
        'read_at'
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return MenuOrderStatus::safeLabel($this->status);
    }


    protected static function boot()
    {
        parent::boot();

        /*
        |--------------------------------------------------------------------------
        | UUID AUTO
        |--------------------------------------------------------------------------
        */

        static::creating(function ($model) {

            if (!$model->uuid) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::addGlobalScope('view_permissions', function (\Illuminate\Database\Eloquent\Builder $builder) {
            $user = auth()->user();

            $permissionsMap = [
                'view_all_notification_in_preparation' => 'in_preparation',
                'view_all_notification_transferred' => 'transferred',
                'view_all_notification_rejected' => 'rejected',
                'view_all_notification_in_defective' => 'defective',
                'view_all_notification_in_ready' => 'ready',
                'view_all_notification_in_delivered' => 'delivered',
                'view_all_notification_in_rejected_after_validation' => 'rejected_after_validation',
                'view_all_notification_in_cancel_for_new_update' => 'cancel_for_new_update',
                'view_all_notification_in_partial_completed' => 'partial_completed',
                'view_all_notification_in_partial_delivered' => 'partial_delivered',
            ];

            $allowedStatuses = [];

            foreach ($permissionsMap as $permission => $status) {
                if ($user->can($permission)) {
                    $allowedStatuses[] = $status;
                }
            }
            if (empty($allowedStatuses)) {
                $allowedStatuses = ['transferred'];
            }

            $builder->whereIn('status', $allowedStatuses);
        });
    }

    // 🔥 relations
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function userNotifications()
    {
        return $this->hasMany(UserOrderNotification::class, 'order_notification_uuid', 'uuid');
    }
    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }
    public static function createOrUpdateNotification(
        string $orderUuid,
        string $status,
        string $message,
        int $userId,
        string $target
    ) {
        self::where('order_menu_restaurant_uuid', $orderUuid)
            ->where('status', $status)
            ->where('target', $target)
            ->forceDelete();

        $barUserId = null;
        $kitchenUserId = null;
        $barmanRole = Role::where('name', 'BARMAN')->first();
        $cuisinierRole = Role::where('name', 'CUISINIER')->first();

        if ($target === 'bar' && $barmanRole) {
            $firstBarman = $barmanRole->first();
            $barUserId = $firstBarman?->id;
        }

        if ($target === 'kitchen' && $cuisinierRole) {
            $firstCuisinier = $cuisinierRole->first();
            $kitchenUserId = $firstCuisinier?->id;
        }
        if ($target === 'all') {
            if ($barmanRole) {
                $firstBarman = $barmanRole->first();
                $barUserId = $firstBarman?->id;
            }
            if ($cuisinierRole) {
                $firstCuisinier = $cuisinierRole->first();
                $kitchenUserId = $firstCuisinier?->id;
            }
        }

        return self::create([
            'order_menu_restaurant_uuid' => $orderUuid,
            'status' => $status,
            'target' => $target,
            'message' => $message,
            'is_read' => false,
            'read_at' => null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'bar_user_id' => $barUserId,
            'kitchen_user_id' => $kitchenUserId,
        ]);
    }
    public function kitchen_users()
    {
        return $this->belongsToMany(User::class, 'kitchen_user_id', 'bar_user_id');
    }

    public function editing_orders()
    {
        return $this->belongsToMany(User::class, 'editing_by', 'editing_by');
    }
}

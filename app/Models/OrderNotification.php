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
        'read_at',
        'is_operational',
        'is_decisional',
        'is_operational_read',
        'operational_read_at',
        'is_decisional_read',
        'decisional_read_at',
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

        return self::create([
            'order_menu_restaurant_uuid' => $orderUuid,
            'status' => $status,
            'target' => $target,
            'message' => $message,
            'created_by' => $userId,
            'updated_by' => $userId,
            'is_operational' => true,
            'is_decisional' => false,
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

    public function myNotification()
    {
        return $this->hasOne(UserOrderNotification::class, 'order_notification_uuid', 'uuid')
            ->where('user_id', auth()->id());
    }
    public function reads()
    {
        return $this->hasMany(
            NotificationRead::class,
            'notification_uuid',
            'uuid'
        );
    }
}

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
        'status',
        'message',
        'created_by',
        'updated_by',
        'target',
        'user_id',
        'is_read',
        'read_at',
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return MenuOrderStatus::safeLabel($this->status);
    }


    protected static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
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

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }
    public static function createOrUpdateNotification(string $orderUuid, string $status, string $message, int $userId, string $target) {
        self::where('order_menu_restaurant_uuid', $orderUuid)
            ->where('status', $status)
            ->where('target', $target)
            ->delete();

        return self::create([
            'order_menu_restaurant_uuid' => $orderUuid,
            'status' => $status,
            'target' => $target,
            'message' => $message,
            'user_id' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }


    public function user()
    {
        return $this->belongsToMany(User::class, 'user_id', 'user_id');
    }

}

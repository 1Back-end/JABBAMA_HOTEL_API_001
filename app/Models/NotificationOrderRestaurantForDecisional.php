<?php

namespace App\Models;

use App\Enums\MenuOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class NotificationOrderRestaurantForDecisional extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'notifications_for_decisionals';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_menu_restaurant_uuid',
        'user_id',
        'status',
        'message',
        'is_read',
        'read_at',
        'updated_by',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
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

    public function orderMenuRestaurant()
    {
        return $this->belongsTo(
            OrderMenuRestaurant::class,
            'order_menu_restaurant_uuid',
            'uuid'
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function createDecisionalOrUpdateNotification(
        string $orderUuid,
        string $status,
        string $message,
        int $recipientId,
        ?int $updatedBy = null
    ) {
        return static::updateOrCreate(
            [
                'order_menu_restaurant_uuid' => $orderUuid,
                'user_id' => $recipientId,
                'status' => $status,
            ],
            [
                'message' => $message,
                'is_read' => false,
                'read_at' => null,
                'updated_by' => $updatedBy ?? auth()->id(),
            ]
        );
    }
}

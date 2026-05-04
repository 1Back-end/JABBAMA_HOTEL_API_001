<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OrderItemStatusEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'order_item_status_events';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'order_menu_restaurant_item_uuid',
        'status',
        'quantity',
        'action_type',
        'comment',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    |----------------------------------------------------
    | RELATIONS
    |----------------------------------------------------
    */

    public function order()
    {
        return $this->belongsTo(
            OrderMenuRestaurant::class,
            'order_menu_restaurant_uuid',
            'uuid'
        );
    }

    public function item()
    {
        return $this->belongsTo(
            OrderMenuRestaurantItem::class,
            'order_menu_restaurant_item_uuid',
            'uuid'
        );
    }

    /*
    |----------------------------------------------------
    | SCOPES UTILES
    |----------------------------------------------------
    */

    public function scopeLatest($query)
    {
        return $query->orderByDesc('created_at');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForItem($query, $itemUuid)
    {
        return $query->where('order_menu_restaurant_item_uuid', $itemUuid);
    }

    public function scopeForOrder($query, $orderUuid)
    {
        return $query->where('order_menu_restaurant_uuid', $orderUuid);
    }
}

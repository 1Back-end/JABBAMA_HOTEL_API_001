<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StatisticsOrderStatusMenuRestaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'statistics_orders_status_menus_restaurants';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_item_uuid',
        'order_menu_restaurant_uuid',
        'status',
        'quantity',

        'created_by',
        'updated_by',

        'pending_at',
        'make_pending_by',
        'rejected_at',
        'make_rejected_by',
        'delivered_at',
        'make_delivered_by',
        'ready_at',
        'make_ready_by',
        'not_delivered_at',
        'make_not_delivered_by',
        'partial_delivered_at',
        'make_partial_delivered_by',
        'delivered_in_preparation_at',
        'make_delivered_in_preparation_by',
        'transferred_at',
        'make_transferred_by',
        'cancel_for_new_update_at',
        'make_cancel_for_new_update_by',
        'in_preparation_at',
        'make_in_preparation_by',
        'partial_completed_at',
        'make_partial_completed_by',
        'new_rejected_at',
        'make_new_rejected_by',
        'type'
    ];

    protected $dates = [
        'pending_at',
        'rejected_at',
        'delivered',
        'ready_at',
        'not_delivered_at',
        'partial_delivered_at',
        'delivered_in_preparation_at',
        'transferred_at',
        'cancel_for_new_update_at',
        'in_preparation_at',
        'partial_completed_at',
        'new_rejected_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function make_delivered_in_preparation_by()
    {
        return $this->belongsTo(User::class, 'make_delivered_in_preparation_by');
    }
    public function make_transferred_by()
    {
        return $this->belongsTo(User::class, 'make_transferred_by');
    }
    public function make_cancel_for_new_update_by()
    {
        return $this->belongsTo(User::class, 'make_cancel_for_new_update_by');
    }
    public function make_in_preparation_by()
    {
        return $this->belongsTo(User::class, 'make_in_preparation_by');
    }
    public function make_new_rejected_by()
    {
        return $this->belongsTo(User::class, 'make_new_rejected_by');
    }
    public function make_pending_by()
    {
        return $this->belongsTo(User::class, 'make_pending_by');
    }
    public function make_rejected_by()
    {
        return $this->belongsTo(User::class, 'make_rejected_by');
    }
    public function make_delivered_by()
    {
        return $this->belongsTo(User::class, 'make_delivered_by');
    }
    public function make_ready_by()
    {
        return $this->belongsTo(User::class, 'make_ready_by');
    }
    public function make_not_delivered_by()
    {
        return $this->belongsTo(User::class, 'make_not_delivered_by');
    }
    public function make_partial_delivered_by()
    {
        return $this->belongsTo(User::class, 'make_partial_delivered_by');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderMenuRestaurantItem::class, 'order_menu_restaurant_item_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

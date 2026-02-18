<?php

namespace App\Models;

use App\Enums\OrderMenuRestaurantItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class OrderMenuRestaurantItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'orders_menu_restaurant_items';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'menus_restaurant_uuid',
        'quantity',
        'unit_price',
        'total_price',
        'is_free',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'quantity'    => 'integer',
        'unit_price'  => 'integer',
        'total_price' => 'integer',
        'is_free'     => 'boolean',
    ];

    protected $appends = ['status_item_order_label'];

    public function getStatusItemOrderLabelAttribute(): string
    {
        return OrderMenuRestaurantItemStatus::safeLabel($this->status);
    }



    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relations
     */
    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function menu()
    {
        return $this->belongsTo(MenuRestaurant::class, 'menus_restaurant_uuid', 'uuid');
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

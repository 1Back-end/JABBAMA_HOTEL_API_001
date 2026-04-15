<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderMenuItemStatusForDrink extends Model
{
    use SoftDeletes,HasFactory;

    protected $table = 'orders_menu_item_statuses_for_drinks';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'order_restaurant_drink_uuid',
        'product_uuid',
        'status',
        'quantity',
        'quantity_exactly',
        'quantity_accumulated',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['quantities_by_status'];

    public function getQuantitiesByStatusAttribute(): array
    {
        if (!$this->order_restaurant_drink_uuid) {
            return [];
        }

        return static::query()
            ->where('order_restaurant_drink_uuid', $this->order_restaurant_drink_uuid)
            ->pluck('quantity', 'status')
            ->toArray();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function drink()
    {
        return $this->belongsTo(OrderRestaurantDrink::class, 'order_restaurant_drink_uuid', 'uuid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
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

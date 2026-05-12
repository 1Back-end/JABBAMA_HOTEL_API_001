<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LastStatusDrinksMenusRestaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'last_status_drinks_menus_restaurants';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'order_restaurant_drink_uuid',
        'product_uuid',
        'type',
        'last_status',
        'created_by',
        'updated_by',
        'drink_restaurant_uuid'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }


    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function drink()
    {
        return $this->belongsTo(OrderRestaurantDrink::class, 'order_restaurant_drink_uuid', 'uuid');
    }
    public function drinkConfig()
    {
        return $this->belongsTo(RestaurantDrinkConfiguration::class, 'drink_restaurant_uuid', 'uuid');
    }
}

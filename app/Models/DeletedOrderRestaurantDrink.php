<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DeletedOrderRestaurantDrink extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'deleted_order_restaurant_drinks';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'drink_restaurant_uuid',
        'quantity_ordered',
        'unit_price',
        'created_by',
        'updated_by',
        'deleted_by',
        'deletion_reason',
    ];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function drinkConfig()
    {
        return $this->belongsTo(RestaurantDrinkConfiguration::class, 'drink_restaurant_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}

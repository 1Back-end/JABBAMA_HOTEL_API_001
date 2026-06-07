<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderMenuRestaurantItemComplement extends Model
{
    use SoftDeletes;

    protected $table = 'orders_items_complements';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'created_by',
        'updated_by',
        'order_menu_restaurant_item_uuid',
        'configuration_complement_uuid',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function orderItem()
    {
        return $this->belongsTo(
            OrderMenuRestaurantItem::class,
            'order_menu_restaurant_item_uuid',
            'uuid'
        );
    }

    public function complement()
    {
        return $this->belongsTo(
            ConfigurationsComplement::class,
            'configuration_complement_uuid',
            'uuid'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    //
}

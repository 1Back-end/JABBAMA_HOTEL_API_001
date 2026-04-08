<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OrderMenuItemStatus extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'order_menu_item_statuses';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_item_uuid',
        'status',
        'quantity',
        'created_by',
        'updated_by',
        'quantity_exactly',
        'quantity_accumulated'
    ];

    protected $appends = ['quantities_by_status'];

    public function getQuantitiesByStatusAttribute(): array
    {
        return self::where('order_menu_restaurant_item_uuid', $this->order_menu_restaurant_item_uuid)
            ->get()
            ->mapWithKeys(fn($status) => [$status->status => $status->quantity])
            ->toArray();
    }


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
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

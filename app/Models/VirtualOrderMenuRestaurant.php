<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class VirtualOrderMenuRestaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'virtual_orders_menu_restaurants';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'orders_menu_restaurant_uuid',
        'item_uuid',
        'product_uuid',
        'quantity_reserved',
        'quantity_delivered',
        'quantity_exactly',
        'quantity_delivered_exactly',
        'quantity_in_defective',
        'created_by',
        'updated_by',
        'status',
        'item_type',
        'is_new_items',
        'is_last_items',
        'quantity'
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

    /**
     * Casts
     */
    protected $casts = [
        'quantity_reserved' => 'integer',
        'is_last_items' => 'boolean',
        'is_new_items' => 'boolean',
    ];



    /**
     * Relations
     */

    // 🔗 Commande principale
    public function order()
    {
        return $this->belongsTo(
            OrderMenuRestaurant::class,
            'orders_menu_restaurant_uuid',
            'uuid'
        );
    }

    // 🔗 Produit
    public function product()
    {
        return $this->belongsTo(
            Product::class,
            'product_uuid',
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
}

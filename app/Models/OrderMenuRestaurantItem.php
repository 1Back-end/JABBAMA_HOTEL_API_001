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
        'quantity_delivered',
        'has_been_validated',
        'unit_price',
        'total_price',
        'is_free',
        'description',
        'status',
        'created_by',
        'updated_by',
        'quantity_exactly',
        'quantity_final_used',
        'rejected_by',
        'rejected_at',
        'is_rejected',
        'reason',
        'is_new_items',
        'is_last_items',
        'make_in_preparation_at',
        'is_stock_deducted'
    ];

    /**
     * Casts
     */
    protected $casts = [
        'quantity'    => 'integer',
        'unit_price'  => 'integer',
        'total_price' => 'integer',
        'is_free'     => 'boolean',
        'is_last_items' => 'boolean',
        'is_new_items' => 'boolean',
        'is_stock_deducted' => 'boolean', // ✅ AJOUT

    ];

    protected $appends = ['status_item_order_label','total_reserved_quantity'];


    public function getStatusItemOrderLabelAttribute(): string
    {
        return OrderMenuRestaurantItemStatus::safeLabel($this->status);
    }

    public function getTotalReservedQuantityAttribute(): array
    {
        return ['total' => (int) $this->virtuals()->whereNull('deleted_at')->sum('quantity_reserved'),
            'status' => 'pending'
        ];
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
    public function virtuals()
    {
        return $this->hasMany(VirtualOrderMenuRestaurant::class, 'item_uuid', 'uuid')
            ->whereNull('deleted_at');
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
    public function statuses()
    {
        return $this->hasMany(OrderMenuItemStatus::class, 'order_menu_restaurant_item_uuid', 'uuid');
    }
}

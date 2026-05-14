<?php

namespace App\Models;

use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderRestaurantDrink extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'order_restaurannts_drinks';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'drink_restaurant_uuid',
        'quantity',
        'unit_price',
        'total_price',
        'status',
        'created_by',
        'updated_by',
        'quantity_delivered',
        'quantity_exactly',
        'has_been_validated',
        'quantity_final_used',
        'rejected_by',
        'rejected_at',
        'is_rejected',
        'reason',
        'is_new_items',
        'is_last_items',
        'make_in_preparation_at',
        'is_defective',
        'reason_of_defective',
        'defective_by',
        'defective_at',
        'is_restored',
        'reason_of_restoration',
        'restorated_by',
        'restorated_at',
        'cancel_for_new_update_at',
        'reason_of_cancel_for_new_update',
        'is_reason_of_cancel_for_new_update',
        'cancel_for_new_update_by',
        'is_reason_of_rejected_after_validation',
        'reason_of_rejected_after_validation',
        'rejected_after_validation_by',
        'rejected_after_validation_at'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'is_last_items' => 'boolean',
        'is_new_items' => 'boolean',
    ];

    protected $appends = ['status_label','total_reserved_quantity'];
    public function getStatusLabelAttribute(): string
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
                $model->uuid = (string) \Str::uuid();
            }

            $model->total_price = $model->quantity * $model->unit_price;
        });

        static::updating(function ($model) {
            $model->total_price = $model->quantity * $model->unit_price;
        });
    }

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function statuses()
    {
        return $this->hasMany(OrderMenuItemStatusForDrink::class, 'order_restaurant_drink_uuid', 'uuid');
    }

    public function virtuals()
    {
        return $this->hasMany(VirtualOrderMenuRestaurant::class, 'item_uuid', 'uuid');
    }
    public function drinkConfig()
    {
        return $this->belongsTo(
            RestaurantDrinkConfiguration::class,
            'drink_restaurant_uuid',
            'uuid'
        )->withDefault([
            'drink_name' => 'Non défini',
        ]);
    }

    public function defectiveByUser()
    {
        return $this->belongsTo(User::class, 'defective_by');
    }

    public function restoredByUser()
    {
        return $this->belongsTo(User::class, 'restorated_by');
    }
    public function cancelForNewUpdateBy()
    {
        return $this->belongsTo(User::class, 'cancel_for_new_update_by');
    }
    public function rejectedAfterValidationByUser()
    {
        return $this->belongsTo(User::class, 'rejected_after_validation_by');
    }


//    public function lastStatus()
//    {
//        return $this->hasOne(LastStatusDrinksMenusRestaurant::class, 'order_restaurant_drink_uuid', 'uuid');
//    }
//    public function defectives()
//    {
//        return $this->hasMany(OrderMenuRestaurantDefectiveDrink::class, 'order_restaurant_drink_uuid', 'uuid');
//    }
//    public function statistics()
//    {
//        return $this->hasMany(StatisticsOrderStatusDrink::class, 'order_restaurant_drink_uuid', 'uuid');
//    }
}

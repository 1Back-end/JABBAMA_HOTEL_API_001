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
        'product_uuid',
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
        'make_in_preparation_at'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'is_last_items' => 'boolean',
        'is_new_items' => 'boolean',
    ];

    protected $appends = ['status_label'];
    public function getStatusLabelAttribute(): string
    {
        return OrderMenuRestaurantItemStatus::safeLabel($this->status);
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
}

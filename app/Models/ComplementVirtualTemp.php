<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ComplementVirtualTemp extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'complement_virtual_temps';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'quantity',
        'quantity_used',
        'product_uuid',
        'reservation_uuid',
        'order_menu_restaurant_uuid',
        'complement_uuid',
        'status',
        'status',
        'created_by',
        'updated_by',
        'last_activity_at',
        'type',
        'menu_uuid',
        'cart_line_uuid'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function complement()
    {
        return $this->belongsTo(ConfigurationsComplement::class, 'complement_uuid', 'uuid');
    }

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function menu()
    {
        return $this->belongsTo(MenuRestaurant::class, 'menu_uuid', 'uuid');
    }

}

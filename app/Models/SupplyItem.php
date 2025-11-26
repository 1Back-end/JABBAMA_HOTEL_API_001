<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SupplyItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supply_items';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'supply_uuid',
        'product_uuid',
        'quantity_supplied',
        'notes',
        'created_by',
        'updated_by',
        'purchase_price',
        'unit_price',
        'supplier_uuid',
        'sell_price'
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }


    public function supply()
    {
        return $this->belongsTo(Supply::class, 'supply_uuid', 'uuid');
    }
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');

    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}

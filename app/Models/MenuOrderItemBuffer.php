<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuOrderItemBuffer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menu_order_items_buffer';
    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'menu_order_uuid',
        'product_uuid',
        'quantity_used',
        'stock_initial',
        'warehouse_uuid',
        'created_by',
        'updated_by',
    ];

    public function order()
    {
        return $this->belongsTo(MenuOrder::class, 'menu_order_uuid', 'uuid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }
}

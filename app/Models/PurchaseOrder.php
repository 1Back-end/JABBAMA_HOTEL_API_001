<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseOrder extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'purchase_orders';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'reference',
        'type',
        'status',
        'warehouse_from',
        'warehouse_to',
        'supplier_uuid',
        'notes',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'closed_at',
        'transfered_by',
        'transfered_at',
        'motif_rejet',
        'unit_price',
        'parent_uuid',
        'is_parent',
    ];




    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $prefix = 'CMD';
            $timestamp = now()->format('ymdHi');

            $random = strtoupper(Str::random(7));
            $model->reference = $prefix . $timestamp . $random;
            $model->uuid = (string) Str::uuid();
        });
    }


    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_uuid', 'uuid');
    }


    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }

    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_from', 'uuid');
    }

    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_to', 'uuid');
    }


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by','id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by','id');
    }
    public function transfered()
    {
        return $this->belongsTo(User::class, 'transfered_by');
    }


    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }
    public function children()
    {
        return $this->hasMany(PurchaseOrder::class, 'parent_uuid', 'uuid');
    }

    public function parent()
    {
        return $this->belongsTo(PurchaseOrder::class, 'parent_uuid', 'uuid');
    }



}
?>

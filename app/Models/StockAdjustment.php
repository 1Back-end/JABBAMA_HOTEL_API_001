<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class StockAdjustment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stock_adjustments';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'reference',
        'warehouse_uuid',
        'notes',
        'comment',
        'action',
        'created_by',
        'updated_by',
        'validated_by',
        'validated_at',
        'status',
        'cancelled_by',
        'cancelled_at',
    ];

    /**
     * Auto-génération UUID
     */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $prefix = 'REG_';
            $timestamp = now()->format('ymdHi');

            $random = strtoupper(Str::random(7));
            $model->reference = $prefix . $timestamp . $random;
            $model->uuid = (string) Str::uuid();
        });
    }

    /* -----------------------------------
     *            RELATIONS
     * ----------------------------------- */

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
    public function cancelled()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}

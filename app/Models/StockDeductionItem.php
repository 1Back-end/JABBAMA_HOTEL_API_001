<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class StockDeductionItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stocks_deductions_items';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'stocks_deduction_uuid',
        'product_uuid',
        'quantity',
        'created_by',
        'updated_by',
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    /* ================= RELATIONS ================= */

    public function stockDeduction()
    {
        return $this->belongsTo(
            StockDeduction::class,
            'stocks_deduction_uuid',
            'uuid'
        );
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
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

<?php

namespace App\Models;

use App\Enums\StocksDeductionsStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StockDeduction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stocks_deductions';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'reference',
        'warehouse_uuid',
        'comment',
        'action',
        'created_by',
        'updated_by',
        'validated_by',
        'validated_at',
        'cancelled_by',
        'cancelled_at',
        'status',
        'reason_of_cancel'
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return StocksDeductionsStatus::safeLabel($this->status);
    }

    /* ================= RELATIONS ================= */

    public function items()
    {
        return $this->hasMany(
            StockDeductionItem::class,
            'stocks_deduction_uuid',
            'uuid'
        );
    }
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $prefix = 'DEDUC_';
            $timestamp = now()->format('ymdHi');

            $random = strtoupper(Str::random(5));
            $model->reference = $prefix . $timestamp . $random;
            $model->uuid = (string) Str::uuid();
        });
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
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

    public function canceler()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    //
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashCollectionFamily extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'cash_collection_families';
    protected $primaryKey = 'uuid';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'target_sector',
        'description',
        'is_active',
        'created_by',
        'updated_by',
        'cash_receipt_type_uuid'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function scopeForRestaurant(Builder $query): Builder
    {
        return $query->whereIn('target_sector', ['restaurant', 'all'])->where('is_active', true);
    }

    public function scopeForBar(Builder $query): Builder
    {
        return $query->whereIn('target_sector', ['bar', 'all'])->where('is_active', true);
    }


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function cashReceiptType()
    {
        return $this->belongsTo(CashReceiptType::class, 'cash_receipt_type_uuid');
    }

    public function SubcashCollectionFamily()
    {
        return $this->belongsTo(SubCashCollectionFamily::class, 'cash_collection_family_uuid');
    }
}

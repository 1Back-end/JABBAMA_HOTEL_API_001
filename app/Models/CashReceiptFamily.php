<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CashReceiptFamily extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'cash_receipt_families';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'cash_receipt_type_uuid',
        'indexation',
        'is_family',
        'is_sub_family',
        'description',
        'created_by',
        'updated_by',
        'parent_uuid',
        'level',
        'is_used'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function cashReceiptType()
    {
        return $this->belongsTo(
            CashReceiptType::class,
            'cash_receipt_type_uuid',
            'uuid'
        );
    }

    public function creator()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function updater()
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }
    public function parent()
    {
        return $this->belongsTo(
            CashReceiptFamily::class,
            'parent_uuid',
            'uuid'
        );
    }
    public function children()
    {
        return $this->hasMany(
            CashReceiptFamily::class,
            'parent_uuid',
            'uuid'
        );
    }
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }
    public function paymentRegulations()
    {
        return $this->hasMany(
            PaymentRegulation::class,
            'cash_receipt_families_uuid',
            'uuid'
        );
    }
}

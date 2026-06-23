<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PaymentRegulation extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'payment_uuid',
        'regulation_method_uuid',
        'amount',
        'phone_number',
        'reference',
        'detail',
        'reason_for_cancel_or_update',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    protected $casts = [
        'status' => PaymentStatus::class,
    ];


    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_uuid', 'uuid');
    }

    public function method()
    {
        return $this->belongsTo(
            RegulationMethod::class,
            'regulation_method_uuid',
            'uuid'
        );
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

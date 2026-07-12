<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PaymentLine extends Model
{
    use SoftDeletes;

    protected $table = 'payment_lines';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'payment_uuid',
        'payable_type',
        'payable_uuid',
        'amount',
        'regulation_method_uuid',
        'payment_regulation_uuid',
        'phone_number',
        'reference',
        'detail',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
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


    public function payable()
    {
        return $this->morphTo(null, 'payable_type', 'payable_uuid');
    }

    public function item()
    {
        return $this->belongsTo(
            OrderMenuRestaurantItem::class,
            'payable_uuid',
            'uuid'
        )->where('payable_type', OrderMenuRestaurantItem::class);
    }

    public function drink()
    {
        return $this->belongsTo(
            OrderRestaurantDrink::class,
            'payable_uuid',
            'uuid'
        )->where('payable_type', OrderRestaurantDrink::class);
    }

    public function payment_regulation()
    {
        return $this->belongsTo(PaymentRegulation::class, 'payment_regulation_uuid', 'uuid');
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

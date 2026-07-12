<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $table = 'payments';
    use SoftDeletes,HasFactory;

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'status',
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

    public function order()
    {
        return $this->belongsTo(
            OrderMenuRestaurant::class,
            'order_menu_restaurant_uuid',
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


    public function updateStatus()
    {
        if ($this->paid_amount <= 0) {
            $this->status = 'unpaid';
        } elseif ($this->paid_amount < $this->total_amount) {
            $this->status = 'partial';
        } else {
            $this->status = 'paid';
        }

        $this->remaining_amount = max(
            0,
            $this->total_amount - $this->paid_amount
        );
    }
    public function regulations()
    {
        return $this->hasMany(PaymentRegulation::class, 'payment_uuid', 'uuid')
            ->orderBy('created_at', 'desc');
    }
}

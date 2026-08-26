<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomService extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'room_services';

    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'prices',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'prices' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function paymentLines()
    {
        return $this->hasMany(
            PaymentLine::class,
            'payable_uuid',
            'uuid'
        )->where('payable_type', RoomService::class);
    }

    public function paymentLinesForOrder(?string $orderUuid)
    {
        return $this->hasMany(
            PaymentLine::class,
            'payable_uuid',
            'uuid'
        )->where('payable_type', RoomService::class)
            ->whereNull('deleted_at')
            ->whereHas('payment', function ($q) use ($orderUuid) {
                $q->where('order_menu_restaurant_uuid', $orderUuid);
            });
    }
}

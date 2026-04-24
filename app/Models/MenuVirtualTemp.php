<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuVirtualTemp extends Model
{
    use SoftDeletes,HasFactory;

    protected $table = 'menu_virtuals_temp';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'quantity',
        'menus_restaurant_uuid',
        'reservation_uuid',
        'created_by',
        'updated_by',
        'product_uuid',
        'quantity_used',
        'status',
        'order_menu_restaurant_uuid',
        'last_activity_at'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
            $model->code = self::generateCode();
        });
    }

    public static function generateCode(): string
    {
        $datePart = now()->format('Ymd'); // ✔ correction du format
        $prefix = '#' . $datePart;
        $last = self::withTrashed()->where('code', 'like', $prefix . '%')->orderBy('code', 'desc')->first();

        if ($last && preg_match('/(\d{10})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }
        return $prefix . str_pad($number, 10, '0', STR_PAD_LEFT);
    }

    public function menuOrder()
    {
        return $this->belongsTo(MenuRestaurant::class, 'menus_restaurant_uuid', 'uuid');
    }

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }
}

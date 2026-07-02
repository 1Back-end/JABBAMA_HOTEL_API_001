<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DrinksVirtualTemp extends Model
{

    use SoftDeletes,HasFactory;

    protected $table = 'drinks_virtuals_temp';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'quantity',
        'created_by',
        'updated_by',
        'product_uuid',
        'quantity_used',
        'reservation_uuid',
        'order_menu_restaurant_uuid',
        'type',
        'status',
        'drink_restaurant_uuid',
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
        $datePart = now()->format('Ydm');
        $prefix = '#' . $datePart;
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{8})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }
        do {
            $generatedCode = $prefix . str_pad($number, 8, '0', STR_PAD_LEFT);
            $exists = self::withTrashed()->where('code', $generatedCode)->exists();

            if ($exists) {
                $number++;
            }
        } while ($exists);

        return $generatedCode;
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

    public function order()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }
    public function drinkRestaurant()
    {
        return $this->belongsTo(
            RestaurantDrinkConfiguration::class,
            'drink_restaurant_uuid',
            'uuid'
        );
    }
}

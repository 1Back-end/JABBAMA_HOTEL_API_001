<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RestaurantDrinkConfiguration extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'restaurant_drink_configurations';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'prices_for_clients_debtor',
        'prices_for_clients_partner',
        'prices_for_clients_free',
        'product_uuid',
        'description',
        'is_active',
        'created_by',
        'updated_by',
        'has_prices',
        'default_price',
        'is_finished_product',
        'is_transformable_product',
        'drink_name'
    ];

    protected $casts = [
        'prices_for_clients_debtor' => 'array',
        'prices_for_clients_partner' => 'array',
        'prices_for_clients_free' => 'array',
        'is_active' => 'boolean',
        'default_price' => 'decimal:2',
    ];

    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm'); // Année, jour, mois → ex: 20252611
        $prefix = '#' . $datePart;

        // Chercher le dernier code global (pas par jour)
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{6})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
            $model->code = self::generateCode();
        });
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
        return $this->belongsTo(Product::class, 'product_uuid');
    }
}

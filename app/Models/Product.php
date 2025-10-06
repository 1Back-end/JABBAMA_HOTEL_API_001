<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'produits';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'description',
        'category_uuid',
        'unit_uuid',
        'purchase_price',
        'sale_price',
        'stock_quantity',
        'minimum_stock',
        'is_active',
        'created_by',
        'updated_by'
    ];

    /** Relations */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    public function unitMeasure()
    {
        return $this->belongsTo(Unit::class, 'unit_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            $product->uuid = (string) Str::uuid();
            $product->code = self::generateCode();
        });
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();
        if ($last && preg_match('/\d+$/', $last->code, $matches)) {
            $number = (int)$matches[0] + 1;
        } else {
            $number = 1;
        }

        return 'PROD-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}

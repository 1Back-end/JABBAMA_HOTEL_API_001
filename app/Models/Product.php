<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory,SoftDeletes;

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
        'updated_by',
        'image_file'
    ];

    protected $appends = ['product_image'];
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    // Unité de mesure
    public function unitMeasure()
    {
        return $this->belongsTo(Unit::class, 'unit_uuid', 'uuid');
    }

    // Créateur & Modificateur
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Plusieurs sous-catégories
    public function subCategories()
    {
        return $this->belongsToMany(
            SubCategory::class,
            'produit_sub_category',
            'produit_uuid',
            'sub_category_uuid'
        )
            ->using(\App\Models\ProductSubCategory::class) // pivot personnalisé avec UUID
            ->withPivot('uuid', 'is_active')
            ->withTimestamps();
    }

    // Plusieurs points de dépôt
    public function points()
    {
        return $this->belongsToMany(
            Warehouse::class,
            'produit_point',
            'produit_uuid',
            'point_uuid'
        )
            ->using(\App\Models\ProductPoint::class) // pivot personnalisé
            ->withPivot('uuid', 'quantity', 'is_active')
            ->withTimestamps();
    }

    /** ===========================
     *  EVENTS
     *  =========================== */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            $product->uuid = (string) Str::uuid();
            $product->code = self::generateCode();
        });
    }

    /** ===========================
     *  CODE GENERATOR
     *  =========================== */
    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();
        if ($last && preg_match('/\d+$/', $last->code, $matches)) {
            $number = (int) $matches[0] + 1;
        } else {
            $number = 1;
        }

        return 'PROD-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    public function getProductImageAttribute()
    {
        $media = $this->medias()->first(); // premier média
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }
        return null;
    }


}

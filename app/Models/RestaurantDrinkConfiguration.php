<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        'drink_name',
        'image_file',
        'quantity',
        'production_cost',
        'additional_cost',
        'production_cost_final',
    ];

    protected $appends = ['product_image','display_name','calculated_production_cost'];

    protected $casts = [
        'prices_for_clients_debtor' => 'array',
        'prices_for_clients_partner' => 'array',
        'prices_for_clients_free' => 'array',
        'is_active' => 'boolean',
        'default_price' => 'decimal:2',
        'quantity' => 'integer',
        'is_finished_product' => 'boolean',
        'is_transformable_product' => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        return $this->product?->name ?? $this->drink_name ?? 'Boisson';
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
        return $this->belongsTo(
            Product::class,
            'product_uuid',
            'uuid'
        );
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    public function getProductImageAttribute()
    {
        $media = $this->medias()->first();
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }
        return null;
    }
    public function composition()
    {
        return $this->hasOne(
            DrinkComposition::class,
            'drinks_restaurant_uuid',
            'uuid'
        );
    }
    public function getCalculatedProductionCostAttribute(): float
    {
        if (!$this->is_transformable_product) {
            return 0.0;
        }

        $totalCalculatedProductionCost = 0;

        $items = $this->composition?->items ?? [];

        foreach ($items as $item) {
            $quantityUsed = floatval($item->quantity_used ?? 0);

            $latestSupplyItem = DB::table('supply_items as si')
                ->join('supplies as s', 's.uuid', '=', 'si.supply_uuid')
                ->where('si.product_uuid', $item->product_uuid)
                ->whereNotNull('si.unit_price')
                ->whereNull('si.deleted_at')
                ->whereIn('s.status', [
                    \App\Enums\SupplyStatus::VALIDATED->value,
                    \App\Enums\SupplyStatus::PARTIALLY_VALIDATED->value
                ])
                ->orderBy('s.supply_date', 'desc')
                ->orderBy('s.created_at', 'desc')
                ->select('si.unit_price', 's.supply_date', 's.status')
                ->first();

            $unitPrice = $latestSupplyItem ? floatval($latestSupplyItem->unit_price) : 0;

            $itemTotal = $unitPrice * $quantityUsed;
            $totalCalculatedProductionCost += $itemTotal;
        }

        $additionalCost = floatval($this->additional_cost ?? 0);
        $totalCalculatedProductionCost += $additionalCost;

        return round($totalCalculatedProductionCost, 2);
    }
}

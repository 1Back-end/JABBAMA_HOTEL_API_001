<?php

namespace App\Models;

use App\Enums\MenuOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuRestaurant extends Model
{
    use SoftDeletes,HasFactory;

    protected $table = 'menus_restaurants';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'image_file',
        'is_active',
        'unit_price',
        'special_price',
        'free_price',
        'created_by',
        'updated_by',
        'description',
        'category_uuid',
        'type_complement_boisson',
        'is_confectioned',
        'quantity',
        'has_complements',
        'type_complement_menu',
        'quantity_for_type_complement_menu',
        'quantity_for_type_complement_boisson',
        'have_complements',
        'have_drinks',
        'is_generated_from_complement',
        'is_menu',
        'is_drinks',
        'production_cost',
        'additional_cost',
        'production_cost_final',
        'type_menu'
    ];

    protected $casts = [
        'unit_price'    => 'array',
        'special_price' => 'array',
        'free_price'    => 'array',

    ];

    protected $appends = ['image_file_menu_restaurant','calculated_production_cost'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($menu_restaurant) {
            if (empty($menu_restaurant->uuid)) {
                $menu_restaurant->uuid = (string) Str::uuid();
            }

            $menu_restaurant->code = self::generateCode();
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

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'category_uuid');
    }

    public function getImageFileMenuRestaurantAttribute()
    {
        $media = $this->medias()->first();
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }
        return null;
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    public function compositionItems()
    {
        return $this->hasMany(MenuOrderItem::class, 'menus_restaurant_uuid', 'uuid');
    }


    public function complements()
    {
        return $this->belongsToMany(
            ConfigurationsComplement::class,
            'menu_restaurant_complements',
            'menu_restaurant_uuid',
            'complement_uuid'
        )
            ->wherePivotNull('deleted_at')
            ->distinct();
    }

    public function getCalculatedProductionCostAttribute(): float
    {
        $totalCalculatedProductionCost = 0;

        $items = $this->compositionItems;
        foreach ($items as $index => $item) {
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

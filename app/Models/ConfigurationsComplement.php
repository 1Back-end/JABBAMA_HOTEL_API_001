<?php

namespace App\Models;

use App\Enums\MenuComplementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ConfigurationsComplement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'configurations_complements';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'prices_for_clients_debtor',
        'prices_for_clients_partner',
        'prices_for_clients_free',
        'description',
        'is_active',
        'created_by',
        'is_confectioned',
        'updated_by',
        'menus_complement_type',
        'is_sellable_directly',
        'is_menu_and_complement',
        'production_cost',
        'additional_cost',
        'production_cost_final'
    ];

    protected $casts = [
        'prices_for_clients_debtor' => 'array',
        'prices_for_clients_partner' => 'array',
        'prices_for_clients_free' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['status_label','calculated_production_cost'];

    public function getStatusLabelAttribute(): string
    {
        return MenuComplementType::safeLabel($this->menus_complement_type);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($menu_restaurant) {
            $menu_restaurant->uuid = (string) Str::uuid();
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
    public function composition()
    {
        return $this->hasOne(ComplementComposition::class, 'commplements_restaurant_uuid', 'uuid');
    }

    public function compositionItems()
    {
        return $this->hasManyThrough(
            ComplementCompositionItem::class,
            ComplementComposition::class,
            'commplements_restaurant_uuid',
            'complement_uuid',
            'uuid',
            'uuid'
        );
    }
    public function getCalculatedProductionCostAttribute(): float
    {
        $totalCalculatedProductionCost = 0;

        $items = $this->compositionItems;
        foreach ($items as $index => $item) {
            $quantityUsed = floatval($item->quantity_used ?? 0);
            $query = \Illuminate\Support\Facades\DB::table('supply_items as si')
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
                ->select('si.unit_price', 's.supply_date', 's.status');

            $latestSupplyItem = $query->first();

            $unitPrice = $latestSupplyItem ? floatval($latestSupplyItem->unit_price) : 0;

            $itemTotal = $unitPrice * $quantityUsed;
            $totalCalculatedProductionCost += $itemTotal;
        }

        $additionalCost = floatval($this->additional_cost ?? 0);
        $totalCalculatedProductionCost += $additionalCost;

        return round($totalCalculatedProductionCost, 2);
    }
}

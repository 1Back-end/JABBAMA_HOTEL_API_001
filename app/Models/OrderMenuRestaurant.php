<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\PurchaseOrdersStatus;
use App\Enums\TypeClientsForPaiment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class OrderMenuRestaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'orders_menu_restaurants';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';


    protected $fillable = [
        'uuid',
        'code',
        'status',
        'unit_price',
        'total_price',
        'is_for_sale_free',
        'consumption_type',
        'description',
        'reason_cancel',
        'validated_at',
        'cancelled_at',
        'restaurant_table_uuid',
        'created_by',
        'updated_by',
        'validated_by',
        'cancelled_by',
        'type_clients_for_payment',
        'order_menu_restaurant_date',
        'remise',
        'partners_restaurant_uuid',
        'warehouse_uuid',
        'restaurant_room_uuid',
        'menu_restaurant_uuid',
        'free_client_for_restaurant_uuid',
        'quantity',
        'full_name',
        'transfered_at',
        'received_by',
        'transfered_by',
        'rejected_at',
        'rejected_by',
        'reason_rejected',
        'quantity_exactly',
        'full_name_for_client_free',
        'is_adjustment'
    ];

    /**
     * Casts
     */
    protected $casts = [
        'is_for_sale_free' => 'boolean',
        'unit_price'       => 'integer',
        'total_price'     => 'integer',
        'validated_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'order_menu_restaurant_date' => 'datetime',
    ];

    protected $appends = ['consumption_type_label','clients_for_payment_label','status_label','total_items','total_drinks','total_order','summary_items'];

    public function getConsumptionTypeLabelAttribute(): string
    {
        return ConsumptionType::safeLabel($this->consumption_type);
    }
    public function getClientsForPaymentLabelAttribute(): string
    {
        return TypeClientsForPaiment::safeLabel($this->type_clients_for_payment);
    }
    public function getStatusLabelAttribute(): string
    {
        return MenuOrderStatus::safeLabel($this->status);
    }

    public function getTotalItemsAttribute(): int
    {
        return $this->items->sum(fn($item) => $item->total_price ?? 0);
    }

    public function getTotalDrinksAttribute(): int
    {
        return $this->drinks->sum(fn($drink) => $drink->total_price ?? 0);
    }

    public function getTotalOrderAttribute(): int
    {
        return $this->total_items + $this->total_drinks;
    }

    public function getSummaryItemsAttribute(): string
    {
        // Compter le nombre de menus différents
        $menusCount = $this->items()->count();

        // Compter le nombre de boissons différentes
        $drinksCount = $this->drinks()->count();

        $menusLabel = $menusCount > 1 ? 'plats' : 'plat';
        $drinksLabel = $drinksCount > 1 ? 'boissons' : 'boisson';

        return "{$menusCount} {$menusLabel} et {$drinksCount} {$drinksLabel}";
    }


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
                $model->code = self::generateCode();
            }
        });
    }

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

        return $prefix . str_pad($number, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Relations
     */
    public function restaurantTable()
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function cancelor()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function partners_restaurant()
    {
        return $this->belongsTo(RestaurantPartner::class, 'partners_restaurant_uuid', 'uuid');
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }
    public function restaurant_room()
    {
        return $this->belongsTo(RestaurantRoom::class, 'restaurant_room_uuid', 'uuid');
    }
    public function menu_restaurant()
    {
        return $this->belongsTo(MenuRestaurant::class, 'menu_restaurant_uuid', 'uuid');
    }
    // Récupérer tous les items d'une commande
    public function items()
    {
        return $this->hasMany(OrderMenuRestaurantItem::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function received()
    {
        return $this->belongsTo(User::class, 'received_by', 'id');
    }
    public function transfered()
    {
        return $this->belongsTo(User::class, 'transfered_by', 'id');
    }

    public function rejected()
    {
        return $this->belongsTo(User::class, 'rejected_by', 'id');
    }

    public function drinks()
    {
        return $this->hasMany(OrderRestaurantDrink::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function free_client_for_restaurant()
    {
        return $this->belongsTo(FreeClientRestaurant::class, 'free_client_for_restaurant_uuid', 'uuid');
    }

}

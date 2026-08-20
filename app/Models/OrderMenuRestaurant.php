<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
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
        'amount_allocated',
        'transfered_at',
        'received_by',
        'transfered_by',
        'rejected_at',
        'rejected_by',
        'reason_rejected',
        'quantity_exactly',
        'full_name_for_client_free',
        'is_adjustment',
        'reservation_uuid',
        'kitchen_user_id',
        'bar_user_id',
        'is_in_editing',
        'editing_by',
        'editing_started_at',
        'rollback_at',
        'is_restored',
        'sales_category_type',
        'sales_category_uuid',
        'others_informations',
        'regulation_status',
        'created_at',
        'updated_at',
        'is_recouvrement',
        'room_service_uuid',
        'price_for_room_service',
        'is_room_service',
        'quantity_for_room_service',
        'room_service_type'
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

    protected $appends = ['debtor_amount_allocated','items_and_drinks_status','free_client_amount_allocated','total_cumul_arrhes','status_payment_label','partner_amount_allocated','consumption_type_label','clients_for_payment_label','status_label','status_payment_label','total_items','total_drinks','total_order','summary_items','remaining_amount','computed_paid_amount'];
    public function getTotalCumulArrhesAttribute()
    {
        return $this->free_client_amount_allocated
            + $this->partner_amount_allocated
            + $this->debtor_amount_allocated;
    }

    public function getFreeClientAmountAllocatedAttribute()
    {
        $allocated = $this->free_client_for_restaurant?->amount_allocated ?? 0;

        if ($allocated == 0 && in_array($this->regulation_status, [
                PaymentOrderMenusStatus::PAID->value,
                PaymentOrderMenusStatus::PARTIALLY_PAID->value,
            ])) {
            return $this->payment?->paid_amount ?? 0;
        }
        return $allocated;
    }

    public function getPartnerAmountAllocatedAttribute()
    {
        $allocated = $this->partners_restaurant?->amount_allocated ?? 0;

        if ($allocated == 0 && in_array($this->regulation_status, [
                PaymentOrderMenusStatus::PAID->value,
                PaymentOrderMenusStatus::PARTIALLY_PAID->value,
            ])) {
            return $this->payment?->paid_amount ?? 0;
        }
        return $allocated;
    }

    public function getDebtorAmountAllocatedAttribute()
    {
        return self::sum('amount_allocated') ?? 0;
    }

    public function getRemainingAmountAttribute(): int
    {
        $paid = $this->payment->paid_amount ?? 0;
        return max(0, $this->total_order - $paid);
    }
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

    public function getStatusPaymentLabelAttribute(): string
    {
        return PaymentOrderMenusStatus::safeLabel($this->regulation_status);
    }

    public function getTotalItemsAttribute(): float
    {
        return $this->items->sum(function ($item) {
            return (
                ($item->unit_price ?? 0) *
                ($item->quantity_exactly ?? 0)
            );
        });
    }

    public function getTotalDrinksAttribute(): float
    {
        return $this->drinks->sum(function ($drink) {
            return (
                ($drink->unit_price ?? 0) *
                ($drink->quantity_exactly ?? 0)
            );
        });
    }

    public function getItemsAndDrinksStatusAttribute(): array
    {
        return [
            'items' => $this->items->pluck('status')->toArray(),
            'drinks' => $this->drinks->pluck('status')->toArray(),
        ];
    }

    public function getTotalOrderAttribute(): int
    {
        $total = (int) ($this->total_items + $this->total_drinks);

        if ($this->is_room_service) {
            $total += (int) $this->price_for_room_service;
        }
        return $total;
    }

    public function getComputedPaidAmountAttribute(): int
    {
        return $this->total_order - $this->remaining_amount;
    }

    public function getSummaryItemsAttribute(): string
    {
        $menusCount = $this->items()->count();

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

    public function notifications()
    {
        return $this->hasMany(OrderNotification::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function complements()
    {
        return $this->hasMany(ComplementVirtualTemp::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function bar_users()
    {
        return $this->belongsToMany(User::class, 'bar_user_id', 'bar_user_id');
    }
    public function kitchen_users()
    {
        return $this->belongsToMany(User::class, 'kitchen_user_id', 'bar_user_id');
    }

    public function editing_orders()
    {
        return $this->belongsToMany(User::class, 'editing_by', 'editing_by');
    }

    public function complementVirtualTemps()
    {
        return $this->hasMany(ComplementVirtualTemp::class, 'order_menu_restaurant_uuid', 'uuid');
    }
    public function salesCategory()
    {
        return $this->belongsTo(SalesCategory::class, 'sales_category_uuid', 'uuid');
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'order_menu_restaurant_uuid', 'uuid');
    }
    public function roomService()
    {
        return $this->belongsTo(RoomService::class, 'room_service_uuid', 'uuid');
    }

}

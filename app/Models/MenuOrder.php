<?php

namespace App\Models;

use App\Enums\MenuOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menu_orders';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';


    protected $fillable = [
        'code',
        'menus_restaurant_uuid',
        'warehouse_uuid',
        'status',
        'reason_cancel',
        'reason_reject',
        'created_by',
        'updated_by',
        'validated_by',
        'cancelled_by',
        'rejected_by',
        'deleted_by',
        'validated_at',
        'cancelled_at',
        'rejected_at',
        'description'
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
        'cancelled_at',
        'validated_at',
        'cancelled_at',
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return MenuOrderStatus::safeLabel($this->status);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($menu_order) {
            $menu_order->uuid = (string) Str::uuid();
            $menu_order->code = self::generateCode();
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

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    public function menus_restaurant()
    {
        return $this->belongsTo(MenuRestaurant::class, 'menus_restaurant_uuid', 'uuid');
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }
    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
    public function cancelor()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // 🔹 Relation avec les items
    public function items()
    {
        return $this->hasMany(MenuOrderItem::class, 'menu_order_uuid', 'uuid');
    }

    // 🔹 Relation avec le tampon
    public function bufferItems()
    {
        return $this->hasMany(MenuOrderItemBuffer::class, 'menu_order_uuid', 'uuid');
    }

    // 🔹 Relation avec l'utilisateur qui a créé
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

}

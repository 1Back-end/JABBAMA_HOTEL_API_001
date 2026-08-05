<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Warehouse extends Model
{
    use HasFactory, SoftDeletes;



    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'ref',
        'name',
        'stock_type',
        'address',
        'is_active',
        'created_by',
        'updated_by',
        'total_stock',
        'is_primary',
        'is_used_for_restaurant',
        'is_bar_warehouse',
        'is_used_for_drinks_transformation'
    ];

    protected $appends = ['total_stock'];



    protected static function boot()
    {
        parent::boot();

        static::creating(function ($warehouse) {
            $warehouse->uuid = (string) Str::uuid();
            $warehouse->ref = self::generateRef();
        });
    }

    public static function generateRef(): string
    {
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{4})$/', $last->ref, $matches)) {
            $number = (int)$matches[1] + 1;
        } else {
            $number = 1;
        }

        return '#' . date('Ymd') . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /** Relations **/

    // Créateur
    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Dernière modification
    public function updater() {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Managers associés (plusieurs managers possible)
    // Managers associés (plusieurs managers possible)
    public function managers()
    {
        return $this->belongsToMany(
            User::class,
            'warehouse_managers',
            'warehouse_uuid',
            'user_id'
        )
            ->using(WarehouseManager::class)
            ->withPivot(['uuid', 'created_by', 'updated_by', 'deleted_at'])
            ->withTimestamps()
            ->whereNull('warehouse_managers.deleted_at');
    }

    // Natures associées (plusieurs natures possible)
    public function natures()
    {
        return $this->belongsToMany(
            NatureEntrepot::class,
            'nature_warehouse',   // table pivot pour natures
            'warehouse_uuid',     // clé étrangère vers Warehouse
            'nature_uuid'         // clé étrangère vers NatureEntrepot
        )
            ->withPivot(['is_active', 'created_by', 'updated_by'])
            ->withTimestamps()
            ->using(NatureWarehouse::class);
    }
    public function orders()
    {
        return $this->hasMany(PurchaseOrder::class, 'warehouse_from', 'uuid');
    }
    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'produit_point', // table pivot entre produits et entrepôts
            'point_uuid',    // clé étrangère vers Warehouse dans pivot
            'produit_uuid'   // clé étrangère vers Product dans pivot
        )
            ->withPivot('uuid', 'quantity', 'is_active')
            ->withTimestamps()
            ->using(ProductPoint::class); // pivot personnalisé
    }
    public function getTotalStockAttribute()
    {
        return $this->products()
            ->where('produit_point.is_active', true)
            ->sum('produit_point.quantity');
    }



}

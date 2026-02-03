<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'suppliers';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'ref',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'phone_number_2',
        'address',
        'cni_number',
        'description',
        'is_active',
        'company_name',
        'company_email',
        'company_phone',
        'created_by',
        'updated_by',
        'full_name'
    ];

    protected static function boot()
    {
        parent::boot();

        // Avant création
        static::creating(function ($supplier) {
            $supplier->uuid = (string) Str::uuid();
            $supplier->ref = self::generateRef();

            // 🔹 Génération du full_name
            $supplier->full_name = trim(
                ($supplier->first_name ?? '') . ' ' . ($supplier->last_name ?? '')
            );
        });

        // Avant mise à jour
        static::updating(function ($supplier) {
            $supplier->full_name = trim(
                ($supplier->first_name ?? '') . ' ' . ($supplier->last_name ?? '')
            );
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

    public static function generateRef(): string
    {

        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();
        if ($last && preg_match('/\d+$/', $last->ref, $matches)) {
            $number = (int)$matches[0] + 1;
        } else {
            $number = 1;
        }

        return 'FOUR-'  . date('Ymd') . str_pad($number, 4, '0', STR_PAD_LEFT);
    }


    public function orders()
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_uuid', 'uuid');
    }
    public function suppliers()
    {
        return $this->hasMany(SupplySupplier::class, 'supply_uuid', 'uuid')->with('supplier');
    }


}

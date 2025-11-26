<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Supply extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supplies';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'purchase_order_uuid',
        'warehouse_uuid',
        'supplier_uuid',
        'reference',
        'supply_date',
        'status',
        'notes',
        'scanned_documents',
        'created_by',
        'updated_by',
        'validated_by',
        'partially_validated_by',
        'partial_validation_reason',
        'rejected_by',
        'rejection_reason',
        'type',
        'reason_cancel',
        'cancelled_by',
        'unit_price',
        'transferred_at',
        'transferred_by',
        'receiver_by',
        'unit_price',
        'sell_price'
    ];

    protected $casts = [
        'scanned_documents' => 'array',
        'supply_date' => 'datetime',
    ];

    protected $appends = ['scanned_documents_purchase_orders'];
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $prefix = 'APP';
            $timestamp = now()->format('ymdHi');

            $random = strtoupper(Str::random(7));
            $model->reference = $prefix . $timestamp . $random;
            $model->uuid = (string) Str::uuid();
        });
    }

    // 🔗 Relations
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_uuid', 'uuid');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }
    public function supply()
    {
        return $this->hasMany(Supply::class, 'supply_uuid', 'uuid');
    }

    // 🔗 Relation vers les items de l'approvisionnement
    public function items()
    {
        return $this->hasMany(SupplyItem::class, 'supply_uuid', 'uuid');
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
    public function cancelled(){
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
    public function partially_validated()
    {
        return $this->belongsTo(User::class, 'partially_validated_by');
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }
    public function getScannedDocumentsPurchaseOrdersAttribute()
    {
        return $this->medias()
            ->get()
            ->map(function ($media) {
                return [
                    'name' => $media->name,
                    'extension' => $media->extension,
                    'path' => $media->path,
                    'url' => Storage::disk($media->disk)->url($media->path),
                ];
            });
    }

    public function invoices()
    {
        return $this->hasMany(SupplyInvoice::class, 'supply_uuid', 'uuid');
    }
    public function transferredBy()
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_by');
    }
    public function supplySuppliers()
    {
        return $this->hasMany(SupplySupplier::class, 'supply_uuid', 'uuid');

    }
    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_uuid', 'uuid');
    }








}

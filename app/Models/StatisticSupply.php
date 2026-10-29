<?php

namespace App\Models;

use App\Enums\PurchaseOrdersStatus;
use App\Enums\SupplyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StatisticSupply extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'statistics_supplies';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'purchase_order_uuid',
        'product_uuid',
        'supplier_uuid',
        'quantity',
        'status',
        'transfered_at',
        'transfered_by',
        'validated_at',
        'validated_by',
        'rejected_at',
        'rejected_by',
        'received_at',
        'received_by',
        'approved_at',
        'approved_by',
        'cancelled_at',
        'cancelled_by',
        'created_by',
        'updated_by',
        'supply_uuid'
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return SupplyStatus::safeLabel($this->status);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // --- Relations ---

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_uuid', 'uuid');
    }

    public function product()
    {
        // Note: pointe vers la table 'produits' comme défini dans votre migration
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function transferer()
    {
        return $this->belongsTo(User::class, 'transfered_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function supply()
    {
        return $this->belongsTo(Supply::class, 'supply_uuid', 'uuid');
    }
}

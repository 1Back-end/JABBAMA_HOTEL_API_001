<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SupplySupplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supply_suppliers';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'supply_uuid',
        'supplier_uuid',
        'warehouse_uuid',
        'notes',
        'created_by',
        'updated_by'
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Générer automatiquement un UUID lors de la création
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relations

    public function supply()
    {
        return $this->belongsTo(Supply::class, 'supply_uuid', 'uuid');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }







}

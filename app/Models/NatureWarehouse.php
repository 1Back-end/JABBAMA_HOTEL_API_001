<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NatureWarehouse extends Pivot
{
    use HasFactory, SoftDeletes;

    protected $table = 'nature_warehouse';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'warehouse_uuid',
        'nature_uuid',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($pivot) {
            $pivot->uuid = (string) Str::uuid();
        });
    }

    // Relations

    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater() {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid');
    }

    public function nature() {
        return $this->belongsTo(NatureEntrepot::class, 'nature_uuid');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Passation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'passations';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'created_by',
        'updated_by',
        'agent_from_id',
        'agent_to_id',
        'warehouse_uuid',
        'status',
        'validated_by',
        'rejected_by',
        'cancelled_by',
        'reason_rejected',
        'reason_validated',
        'reason_cancelled',
        'rejected_at',
        'cancelled_at',
        'validated_at',
        'quantity_sent',
        'quantity_counted',
        'difference',
    ];
    protected $casts = [
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    // Générer automatiquement un UUID lors de la création
    protected static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
    public function cancellor()
    {
        return $this->belongsTo(User::class, 'cancelled_by');

    }

    // Relations
    public function items()
    {
        return $this->hasMany(PassationItem::class, 'passation_uuid', 'uuid');
    }

    public function agentFrom()
    {
        return $this->belongsTo(User::class, 'agent_from_id');
    }

    public function agentTo()
    {
        return $this->belongsTo(User::class, 'agent_to_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

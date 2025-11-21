<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PassationItem extends Model
{
    use HasFactory;

    protected $table = 'passation_items';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'passation_uuid',
        'product_uuid',
        'quantity_sent',
        'quantity_counted',
        'difference',
        'created_by',
        'updated_by'
    ];

    // Générer automatiquement un UUID
    protected static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    // Relations
    public function passation()
    {
        return $this->belongsTo(Passation::class, 'passation_uuid', 'uuid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
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

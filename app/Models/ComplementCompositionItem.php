<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ComplementCompositionItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'complements_compositions_items';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'complement_uuid',
        'product_uuid',
        'quantity_used',
        'is_optional',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity_used' => 'integer',
        'is_optional' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function composition()
    {
        return $this->belongsTo(
            ComplementComposition::class,
            'complement_uuid',
            'uuid'
        );
    }

    public function product()
    {
        return $this->belongsTo(
            Product::class,
            'product_uuid',
            'uuid'
        );
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

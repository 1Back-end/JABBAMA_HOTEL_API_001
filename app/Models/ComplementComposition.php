<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ComplementComposition extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'drink_compositions';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'drinks_restaurant_uuid',
        'warehouse_uuid',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    /* =======================
        RELATIONS
    ======================= */

    public function complements()
    {
        return $this->belongsTo(
            RestaurantDrinkConfiguration::class,
            'drinks_restaurant_uuid',
            'uuid'
        );
    }

    public function items()
    {
        return $this->hasMany(
            DrinkCompositionItem::class,
            'drink_composition_uuid',
            'uuid'
        );
    }

    public function warehouse()
    {
        return $this->belongsTo(
            Warehouse::class,
            'warehouse_uuid',
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

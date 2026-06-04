<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuRestaurantComplement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menu_restaurant_complements';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'menu_restaurant_uuid',
        'complement_uuid',
        'created_by',
        'updated_by',
        'quantity'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function menu()
    {
        return $this->belongsTo(
            MenuRestaurant::class,
            'menu_restaurant_uuid',
            'uuid'
        );
    }

    public function complement()
    {
        return $this->belongsTo(
            ConfigurationsComplement::class,
            'complement_uuid',
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

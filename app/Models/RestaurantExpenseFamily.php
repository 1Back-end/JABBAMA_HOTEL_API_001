<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class RestaurantExpenseFamily extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'restaurant_expense_types_families';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'indexation',
        'operation_type',
        'description',
        'level',
        'is_used',
        'is_active',
        'parent_uuid',
        'restaurant_expense_uuid',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_uuid', 'uuid');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_uuid', 'uuid');
    }

    public function childrenRecursive()
    {
        return $this->hasMany(
            RestaurantExpenseFamily::class,
            'parent_uuid',
            'uuid'
        )
            ->with([
                'childrenRecursive',
                'type',
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
            ]);
    }

    public function type()
    {
        return $this->belongsTo(
            RestaurantExpenseType::class,
            'restaurant_expense_uuid',
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

    public function families()
    {
        return $this->hasMany(
            RestaurantExpenseFamily::class,
            'restaurant_expense_uuid',
            'uuid'
        )
            ->whereNull('parent_uuid')
            ->with('childrenRecursive');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PassationManager extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'passation_managers';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'passation_uuid',
        'manager_id',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Génération automatique de l'UUID
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relation : ce manager appartient à une passation
     */
    public function passation()
    {
        return $this->belongsTo(Passation::class, 'passation_uuid', 'uuid');
    }

    /**
     * Relation : manager (utilisateur)
     */
    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by','id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by','id');
    }
    //
}

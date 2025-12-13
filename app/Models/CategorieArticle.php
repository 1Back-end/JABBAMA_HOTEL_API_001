<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CategorieArticle extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'categories_articles';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'description',
        'parent_uuid',
        'created_by',
        'updated_by',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relation parent
    public function parent()
    {
        return $this->belongsTo(CategorieArticle::class, 'parent_uuid', 'uuid');
    }

    // Relation enfants
    public function children()
    {
        return $this->hasMany(CategorieArticle::class, 'parent_uuid', 'uuid');
    }

    // Créateur et modificateur
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

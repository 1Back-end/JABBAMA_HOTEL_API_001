<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class CategoryTree extends Model
{
    use SoftDeletes, HasUuids;

    protected $table = 'category_tree';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'category_uuid',
        'children',
        'created_by',
        'updated_by',
        'is_active'
    ];

    protected $casts = [
        'children' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * 🔹 Catégorie racine
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    /**
     * 🔹 Utilisateur créateur
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 🔹 Utilisateur modificateur
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

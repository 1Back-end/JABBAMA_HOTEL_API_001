<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SubCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sub_categories';

    // Clé primaire en UUID
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    // Champs modifiables
    protected $fillable = [
        'code',
        'category_uuid',
        'name',
        'description',
        'is_active',
        'created_by',
        'updated_by',
        'parent_uuid'
    ];

    // Champs cachés / masqués
    protected $hidden = [
        'deleted_at',
    ];

    // Valeurs par défaut
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Boot method pour générer UUID et code automatiquement
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sub_category) {
            $prefix = '#';
            $timestamp = now()->format('ymdHi');

            $random = strtoupper(Str::random(7));
            $sub_category->code = $prefix . $timestamp . $random;
            $sub_category->uuid = (string) Str::uuid();
        });
    }
    /**
     * Relation avec la catégorie parent
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    /**
     * Génération automatique du code unique
     */


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

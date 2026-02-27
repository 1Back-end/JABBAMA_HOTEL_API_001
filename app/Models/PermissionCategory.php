<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PermissionCategory extends Model
{
    use HasFactory;

    protected $table = 'categories_permissions';

    protected $fillable = [
        'libelle',
        'description',
        'active',
        'created_by',
        'updated_by',
    ];

    /**
     * Relation vers l'utilisateur qui a créé la catégorie.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relation vers l'utilisateur qui a mis à jour la catégorie.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Relation vers les permissions appartenant à cette catégorie.
     */
    public function permissions()
    {
        return $this->hasMany(Permission::class, 'category_id');
    }
}

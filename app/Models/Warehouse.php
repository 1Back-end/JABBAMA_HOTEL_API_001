<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'ref',
        'name',
        'nature_uuid',   // remplacé 'nature'
        'stock_type',
        'manager_id', // <--- ajouter ici
        'address',
        'is_active',
        'created_by',
        'updated_by',
        'manager_id',    // nouveau champ
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($warehouse) {
            $warehouse->uuid = (string) Str::uuid();
            $warehouse->ref = self::generateRef();
        });
    }

    public static function generateRef(): string
    {
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{4})$/', $last->ref, $matches)) {
            $number = (int)$matches[1] + 1;
        } else {
            $number = 1;
        }

        return 'ENT-' . date('Ymd') . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /** Relations **/

    // Utilisateur qui a créé
    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Utilisateur qui a modifié
    public function updater() {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Manager de l'entrepôt
    public function manager() {
        return $this->belongsTo(User::class, 'manager_id');
    }

    // Natures associées (via pivot)
    public function natures() {
        return $this->belongsToMany(
            NatureEntrepot::class,
            'nature_warehouse',
            'warehouse_uuid',
            'nature_uuid'
        )->withPivot(['is_active','created_by','updated_by'])->withTimestamps()->using(NatureWarehouse::class);
    }
}

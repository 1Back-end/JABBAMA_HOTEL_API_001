<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuCategory extends Model
{
    use SoftDeletes;

    /**
     * La clé primaire n’est pas auto-incrémentée
     */
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Champs remplissables
     */
    protected $fillable = [
        'uuid',
        'name',
        'code',
        'slug',
        'position',
        'created_by',
        'updated_by',
        'description'
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Génération auto du UUID et du slug
     */
    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm'); // Année, jour, mois → ex: 20252611
        $prefix = 'CATM' . $datePart;

        // Chercher le dernier code global (pas par jour)
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{6})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category_menu) {
            $category_menu->uuid = (string) Str::uuid();
            $category_menu->code = self::generateCode();
        });
    }

    /**
     * Relations
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

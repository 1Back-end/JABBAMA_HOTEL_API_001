<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NatureEntrepot extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'nature_entrepots';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'abbreviation',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($nature) {
            $prefix = 'NAT-ENT-';
            $timestamp = now()->format('ymdHi');

            $random = strtoupper(Str::random(7));
            $nature->code = $prefix . $timestamp . $random;
            $nature->uuid = (string) Str::uuid();
        });
    }



    /**
     * Relations avec les utilisateurs
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

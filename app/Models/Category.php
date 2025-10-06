<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'categories';
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


    protected static function boot()
    {
        parent::boot();

        // Générer uuid et code avant l’insertion
        static::creating(function ($unit) {
            $unit->uuid = (string) Str::uuid();
            $unit->code = self::generateCode();
        });
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();
        if ($last && preg_match('/\d+$/', $last->code, $matches)) {
            $number = (int)$matches[0] + 1;
        } else {
            $number = 1;
        }

        return 'CAT-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    //
}

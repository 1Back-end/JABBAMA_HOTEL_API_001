<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RestaurantTable extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'restaurant_tables';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'table_number',
        'capacity',
        'is_available',
        'created_by',
        'updated_by',
        'description'
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'capacity'     => 'integer',
    ];


    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm');
        $prefix = '#' . $datePart;
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{8})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }
        do {
            $generatedCode = $prefix . str_pad($number, 8, '0', STR_PAD_LEFT);
            $exists = self::withTrashed()->where('code', $generatedCode)->exists();

            if ($exists) {
                $number++;
            }
        } while ($exists);

        return $generatedCode;
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($Table) {
            $Table->uuid = (string) Str::uuid();
            $Table->code = self::generateCode();
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
    //
}

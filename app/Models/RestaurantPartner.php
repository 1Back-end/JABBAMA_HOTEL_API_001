<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestaurantPartner extends Model
{
    use HasFactory, SoftDeletes;


    protected $table = 'restaurant_partners';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone_number',
        'second_phone_number',
        'address',
        'logo',
        'description',
        'active',
        'created_by',
        'updated_by',
        'cni_number',
        'is_whatsapp',
        'is_second_whatsapp'
    ];

    protected $appends = ['logo_partners'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($partenaire) {
            $partenaire->uuid = (string) Str::uuid();
            $partenaire->code = self::generateCode();

            if (empty($partenaire->full_name)) {
                $partenaire->full_name = strtoupper(trim($partenaire->first_name . ' ' . ($partenaire->last_name ?? '')));
            }
        });

        static::updating(function ($partenaire) {
            // Met à jour le full_name à chaque modification
            $partenaire->full_name = strtoupper(trim($partenaire->first_name . ' ' . ($partenaire->last_name ?? '')));
        });
    }


    /** ===========================
     *  CODE GENERATOR
     *  =========================== */
    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm'); // Année, jour, mois → ex: 20252611
        $prefix = '#' . $datePart;

        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{6})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getLogoPartnersAttribute()
    {
        $media = $this->medias()->first(); // premier média
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }
        return null;
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }
}

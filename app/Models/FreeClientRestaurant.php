<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FreeClientRestaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'free_clients_restaurants';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'full_name',
        'first_name',
        'last_name',
        'phone_number',
        'second_phone_number',
        'address',
        'cni_number_file',
        'is_active',
        'created_by',
        'updated_by',
        'profession',
        'amount_allocated',
        'amount_allocated_total'
    ];

    protected $appends = ['cni_file_url'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
            $model->code = self::generateCode();

            if (empty($model->full_name)) {
                $model->full_name = strtoupper(trim($model->first_name . ' ' . ($model->last_name ?? '')));
            }
        });

        static::updating(function ($model) {
            $model->full_name = strtoupper(trim($model->first_name . ' ' . ($model->last_name ?? '')));
        });
    }

    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm');
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
    public function getCniFileUrlAttribute()
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

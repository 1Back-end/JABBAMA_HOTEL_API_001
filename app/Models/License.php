<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class License extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'licenses';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'user_id',
        'format',
        'issuer',
        'client_external_id',
        'client_name',
        'client_email',
        'app_id',
        'app_code',
        'app_name',
        'license_id',
        'reference',
        'license_key',
        'status',
        'jti',
        'kid',
        'modules',
        'module_entitlements',
        'issued_at',
        'expires_at',
        'generated_at',
        'signature_alg',
        'signature_kid',
        'signature_encoding',
        'signature_value',
        'raw_certificate',
    ];

    protected $casts = [
        'modules' => 'array',
        'module_entitlements' => 'array',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

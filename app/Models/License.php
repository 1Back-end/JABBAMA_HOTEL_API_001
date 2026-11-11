<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
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

    public function getActiveModulesAttribute(): array
    {
        $entitlements = $this->module_entitlements ?? [];
        $activeModules = [];

        foreach ($entitlements as $entitlement) {
            $code = $entitlement['code'] ?? null;
            $status = $entitlement['status'] ?? null;
            $expiresAt = isset($entitlement['expires_at']) ? Carbon::parse($entitlement['expires_at']) : null;

            if ($code && $status === 'ACTIVE' && $expiresAt && !$expiresAt->isPast()) {
                $activeModules[] = $code;
            }
        }

        return $activeModules;
    }

    public function getModulesStatusDetailsAttribute(): array
    {
        $entitlements = $this->module_entitlements ?? [];
        $details = [];

        // Définir la langue française pour Carbon (ex: "dans 5 minutes", "il y a 2 jours")
        Carbon::setLocale('fr');

        foreach ($entitlements as $entitlement) {
            $code = $entitlement['code'] ?? 'UNKNOWN';
            $name = $entitlement['name'] ?? $code;
            $status = $entitlement['status'] ?? 'INACTIVE';
            $expiresAt = isset($entitlement['expires_at']) ? Carbon::parse($entitlement['expires_at']) : null;

            $isExpired = $expiresAt ? $expiresAt->isPast() : false;
            $isActive = ($status === 'ACTIVE' && !$isExpired);

            if ($expiresAt) {
                $timeRemaining = $expiresAt->diffForHumans();
            } else {
                $timeRemaining = null;
            }

            if ($isActive) {
                $message = "Le module {$name} est actif jusqu'au " . $expiresAt->format('d/m/Y à H:i') . " (expire {$timeRemaining}).";
            } elseif ($isExpired) {
                $message = "La licence du module {$name} a expiré le " . $expiresAt->format('d/m/Y à H:i') . " ({$timeRemaining}).";
            } else {
                $message = "Le module {$name} est inactif ou suspendu.";
            }

            $details[$code] = [
                'name' => $name,
                'status' => $status,
                'is_active' => $isActive,
                'is_expired' => $isExpired,
                'expires_at' => $expiresAt,
                'time_remaining' => $timeRemaining,
                'message' => $message,
            ];
        }

        return $details;
    }

    /**
     * Méthode pratique pour vérifier si un module spécifique est actif.
     */
    public function hasActiveModule(string $moduleCode): bool
    {
        return in_array(strtoupper($moduleCode), $this->active_modules);
    }

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

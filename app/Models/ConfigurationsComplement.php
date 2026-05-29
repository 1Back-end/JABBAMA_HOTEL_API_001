<?php

namespace App\Models;

use App\Enums\MenuComplementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ConfigurationsComplement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'configurations_complements';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'prices_for_clients_debtor',
        'prices_for_clients_partner',
        'prices_for_clients_free',
        'description',
        'is_active',
        'created_by',
        'updated_by',
        'menus_restaurant_uuid',
        'menus_complement_type',
    ];

    protected $casts = [
        'prices_for_clients_debtor' => 'array',
        'prices_for_clients_partner' => 'array',
        'prices_for_clients_free' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return MenuComplementType::safeLabel($this->menus_complement_type);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($menu_restaurant) {
            $menu_restaurant->uuid = (string) Str::uuid();
            $menu_restaurant->code = self::generateCode();
        });
    }

    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm'); // Année, jour, mois → ex: 20252611
        $prefix = '#' . $datePart;

        // Chercher le dernier code global (pas par jour)
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{6})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    public function menuRestaurant()
    {
        return $this->belongsTo(
            MenuRestaurant::class,
            'menus_restaurant_uuid',
            'uuid'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

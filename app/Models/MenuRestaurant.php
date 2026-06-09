<?php

namespace App\Models;

use App\Enums\MenuOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuRestaurant extends Model
{
    use SoftDeletes,HasFactory;

    protected $table = 'menus_restaurants';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'image_file',
        'is_active',
        'unit_price',
        'special_price',
        'free_price',
        'created_by',
        'updated_by',
        'description',
        'category_uuid',
        'type_complement_boisson',
        'is_confectioned',
        'quantity',
        'has_complements',
        'type_complement_menu',
        'quantity_for_type_complement_menu',
        'quantity_for_type_complement_boisson',
        'have_complements',
        'have_drinks',
        'is_generated_from_complement'
    ];

    protected $casts = [
        'unit_price'    => 'array',
        'special_price' => 'array',
        'free_price'    => 'array',

    ];


    protected $appends = ['image_file_menu_restaurant'];



    protected static function boot()
    {
        parent::boot();

        static::creating(function ($menu_restaurant) {
            if (empty($menu_restaurant->uuid)) {
                $menu_restaurant->uuid = (string) Str::uuid();
            }

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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'category_uuid');
    }

    public function getImageFileMenuRestaurantAttribute()
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

    public function compositionItems()
    {
        return $this->hasMany(MenuOrderItem::class, 'menus_restaurant_uuid', 'uuid');
    }
    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'menu_restaurant_products',
            'menus_restaurant_uuid',
            'product_uuid'
        )->withPivot('quantity_used');
    }

    public function complements()
    {
        return $this->belongsToMany(
            ConfigurationsComplement::class,
            'menu_restaurant_complements',
            'menu_restaurant_uuid',
            'complement_uuid'
        )
            ->wherePivotNull('deleted_at')
            ->distinct();
    }





}

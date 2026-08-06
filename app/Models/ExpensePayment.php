<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpensePayment extends Model
{
    use SoftDeletes;

    protected $table = 'expense_payments';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'restaurant_expense_type_uuid',
        'restaurant_expense_family_uuid',
        'regulation_method_uuid',
        'amount',
        'name',
        'description',
        'paid_at',
        'created_by',
        'updated_by',
        'status',
        'hierarchy_uuids',
        'created_at',
        'updated_at',
        'category_document',
        'type_document',
        'slug'
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount'   => 'decimal:2',
        'hierarchy_uuids' => 'array',
    ];
    protected $appends = [
        'hierarchy_families',
        'category_document_url',
        'type_document_url',
    ];

    public function getHierarchyFamiliesAttribute()
    {
        $uuids = $this->hierarchy_uuids;

        if (empty($uuids) || !is_array($uuids)) {
            return collect();
        }

        return RestaurantExpenseFamily::whereIn('uuid', $uuids)
            ->get()
            ->sortBy('level')
            ->values();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }



    public function expenseType()
    {
        return $this->belongsTo(
            RestaurantExpenseType::class,
            'restaurant_expense_type_uuid',
            'uuid'
        );
    }

    public function family()
    {
        return $this->belongsTo(
            RestaurantExpenseFamily::class,
            'restaurant_expense_family_uuid',
            'uuid'
        );
    }

    public function method()
    {
        return $this->belongsTo(
            RegulationMethod::class,
            'regulation_method_uuid',
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

    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    public function getCategoryDocumentUrlAttribute(): ?string
    {
        $media = $this->medias()->where('filename', 'LIKE', '%_cat_%')->latest()->first();
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }

        return null;
    }

    public function getTypeDocumentUrlAttribute(): ?string
    {
        $media = $this->medias()->where('filename', 'LIKE', '%_type_%')->latest()->first();
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }

        return null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'suppliers';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'ref',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'phone_number_2',
        'address',
        'cni_number',
        'description',
        'is_active',
        'company_name',
        'company_email',
        'company_phone',
        'created_by',
        'updated_by',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
            if (empty($model->ref)) {
                $model->ref = self::generateUniqueRef();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function generateUniqueRef(): string
    {
        do {
            $ref = 'SUP-' . date('Ymd') . '-' . strtoupper(Str::random(4));
        } while (self::where('ref', $ref)->exists());

        return $ref;
    }


}

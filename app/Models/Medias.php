<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Medias extends Model
{
    use HasFactory;

    protected $table = 'medias';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'mediable_id',
        'mediable_type',
        'name',
        'disk',
        'path',
        'filename',
        'mimetype',
        'extension',
        'validity'
    ];

    /**
     * Relation morph to parent model (Product, Warehouse, etc.)
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($image) {
            $image->uuid = (string) Str::uuid();
        });
    }



}

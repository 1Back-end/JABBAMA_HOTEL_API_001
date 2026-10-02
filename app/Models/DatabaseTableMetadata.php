<?php

namespace App\Models;

use App\Enums\TableCategoryEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DatabaseTableMetadata extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'database_tables_metadata';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'table_name',
        'category',
        'display_name',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'category' => TableCategoryEnum::class,
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
}

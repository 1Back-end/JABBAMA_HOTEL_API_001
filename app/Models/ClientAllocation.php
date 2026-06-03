<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ClientAllocation extends Model
{
    use SoftDeletes;

    protected $table = 'client_allocations';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'source_type',
        'source_uuid',
        'client_name',
        'amount_allocated',
        'amount_allocated_total'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }
}

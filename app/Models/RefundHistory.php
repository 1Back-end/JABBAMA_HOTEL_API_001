<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RefundHistory extends Model
{
    use SoftDeletes;

    protected $table = 'refund_histories';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'client_allocation_uuid',
        'source_type',
        'source_uuid',
        'amount',
        'created_by',
        'note'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function allocation()
    {
        return $this->belongsTo(
            ClientAllocation::class,
            'client_allocation_uuid',
            'uuid'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

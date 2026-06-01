<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NotificationRead extends Model
{
    use HasUuids,HasFactory;
    protected $table = 'notification_reads';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'notification_uuid',
        'user_id',
        'created_by',
        'updated_by',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    // 🔥 Auto UUID
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // 🔗 Notification relation
    public function notification()
    {
        return $this->belongsTo(
            OrderNotification::class,
            'notification_uuid',
            'uuid'
        );
    }

    // 👤 User relation
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 👤 created_by
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // 👤 updated_by
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getIsReadAttribute(): bool
    {
        return !is_null($this->read_at);
    }
}

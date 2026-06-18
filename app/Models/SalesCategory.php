<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalesCategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sales_categories';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'type',
        'start_time',
        'end_time',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
    ];
    protected $appends = [
        'duration_minutes',
        'duration_human'
    ];

    public function getDurationMinutesAttribute(): int
    {
        if (!$this->start_time || !$this->end_time) {
            return 0;
        }

        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return $start->diffInMinutes($end);
    }

    public function getDurationHumanAttribute(): string
    {
        $minutes = $this->duration_minutes;

        if ($minutes <= 0) {
            return '0h';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return "{$hours}h";
        }

        return "{$hours}h{$mins}";
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
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
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function scopeTimeBased($query)
    {
        return $query->where('type', 'time_based');
    }
    public function scopeManual($query)
    {
        return $query->where('type', 'manual');
    }
    public function isTimeBased(): bool
    {
        return $this->type === 'time_based';
    }
    public function isManual(): bool
    {
        return $this->type === 'manual';
    }
}

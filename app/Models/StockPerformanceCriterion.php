<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockPerformanceCriterion extends Model
{
    use HasUuids;

    protected $table = 'stock_performance_criteria';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'label',
        'min_rate',
        'max_rate',
        'description_template',
        'badge_color',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'min_rate' => 'float',
        'max_rate' => 'float',
        'is_active' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function evaluate(float $taux): ?self
    {
        return self::where('is_active', true)
            ->where('min_rate', '<=', $taux)
            ->where('max_rate', '>=', $taux)
            ->first();
    }
}

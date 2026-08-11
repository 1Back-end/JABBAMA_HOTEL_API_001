<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class OtherCashIn extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'other_cash_ins';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'amount',
        'status',
        'slug',
        'attachment',
        'regulation_method_uuid',
        'created_by',
        'updated_by',
        'cancelled_by',
        'validated_by',
        'reason_of_cancelled',
        'created_at',
        'updated_at',
    ];

    protected $appends = ['attachment_image'];

    public function getAttachmentImageAttribute()
    {
        $media = $this->medias()->first();
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }
        return null;
    }

    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    public function regulationMethod()
    {
        return $this->belongsTo(RegulationMethod::class, 'regulation_method_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}

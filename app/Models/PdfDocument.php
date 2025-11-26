<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PdfDocument extends Model
{
    protected $table = 'pdf_documents';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'disk',
        'path',
        'filename',
        'mimetype',
        'extension',
        'sequence',
        'created_by',
        'updated_by',
        'order_uuid'
    ];

    /**
     * Boot method to auto-generate UUID + auto sequence.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            // UUID automatique
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }

            // Auto-incrément manuel de sequence
            $last = self::max('sequence');
            $model->sequence = $last ? $last + 1 : 1;
        });
    }

    /** Relations */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

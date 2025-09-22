<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Medias extends Model
{

    protected $table = 'medias';
    protected $primaryKey = "uuid";
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'mediable', 'name', 'disk', 'path', 'filename', 'mimetype', 'extension', 'validity'
    ];
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }


}

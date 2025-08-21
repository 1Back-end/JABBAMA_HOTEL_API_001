<?php

namespace App\Models\Trait;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Log;

trait UpdatingUser
{
    public static function bootUpdatingUser(): void
    {
        static::updating(function ($model) {
            $authId = auth()->user()->id;
            $model->updated_by = $authId;
        });

        static::creating(function ($model) {
            if (! $model->created_by) {
                $authId = auth()->user()?->id;

                $model->created_by = $authId;
                $model->updated_by = $authId;
            }
        });
    }
}

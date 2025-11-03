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
            $authUser = auth()->user();
            if ($authUser) {
                $model->updated_by = $authUser->id;
            }
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

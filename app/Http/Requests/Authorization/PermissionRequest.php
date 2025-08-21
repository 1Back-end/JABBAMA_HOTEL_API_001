<?php

namespace App\Http\Requests\Authorization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Unique;

class PermissionRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            $uniqueName = (new Unique('permissions', 'name'))->ignore($this->route('permission'));
            if ($this->route('permission')->system) {
                $nameRequired = '';
            } else {
                $nameRequired = 'required';
            }
        } else {
            $uniqueName = 'unique:permissions,name';
            $nameRequired = 'required';
        }

        return [
            'name' => [$nameRequired, $uniqueName],
            'description' => ['required'],
        ];
    }
}

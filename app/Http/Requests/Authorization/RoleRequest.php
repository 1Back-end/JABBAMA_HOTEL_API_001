<?php

namespace App\Http\Requests\Authorization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class RoleRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            $uniqueRule = (new Unique('roles', 'name'))->ignore($this->route('role'));
        } else {
            $uniqueRule = 'unique:roles,name';
        }

        return [
            'name' => ['required', $uniqueRule],
            'description' => ['required'],
            'accueil_url' => ['nullable'],
            'permissions' => ['array']
        ];
    }
}

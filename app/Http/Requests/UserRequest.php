<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class UserRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->method() == "PUT") {
            $uniqueEmail = (new Unique('users', 'email'))->ignore($this->route('user'));
            $uniqueLogin = (new Unique('users', 'login'))->ignore($this->route('user'));
            $requiredPassword = '';
        } else {
            $uniqueEmail = 'unique:users,email';
            $uniqueLogin = 'unique:users,login';
            $requiredPassword = 'required';
        }

        return [
            'prenom' => ['nullable'],
            'nom_utilisateur' => ['required'],
            'password' => [$requiredPassword],
            'email' => ['required', 'email', 'max:254', $uniqueEmail],
            'login' => ['required', $uniqueLogin],
            'centres' => ['array', 'required'],
            'roles' => ['array'],
            'roles.*' => ['int', 'required', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.unique' => 'Ce login est deja utilisé',
            'email.unique' => 'Ce email est deja utilisé',
        ];
    }
}

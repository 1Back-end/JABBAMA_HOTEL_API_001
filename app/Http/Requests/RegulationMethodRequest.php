<?php

namespace App\Http\Requests;

use App\Enums\TypeClients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class RegulationMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required'],
            'description' => ['nullable'],
            'comment_required' => ['nullable', 'boolean'],
            'phone_method' => ['nullable', 'boolean'],
        ];
    }
}
